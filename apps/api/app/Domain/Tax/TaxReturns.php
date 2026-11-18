<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tax returns (Module 7), built from the ledger. What is filed is what the tax accounts moved in the period (settlement
 * entries left out); the documents behind it are shown rate by rate and one by one, so every figure drills down, and
 * any movement no current document explains (a manual journal, a void, an edit) is listed on its own.
 *
 *   VAT          output: VAT payable (sales); input: VAT receivable (purchases); net = output - input.
 *   Withholding  what was withheld from suppliers' payments (withholding payable), to pay the authority. Tax customers
 *                withheld from the company (withholding receivable) is a credit against profit tax, not part of it.
 *
 * Filing records the return with its figures (039); its period is then locked for that tax's accounts. Settling posts
 * one entry: VAT payable against VAT receivable and the bank for what is due (a VAT credit carries forward), or
 * withholding payable against the bank.
 */
final class TaxReturns
{
    /** tot: turnover tax, where the country pack (or the company) has TOT rates; it posts to its own account (052). */
    public const KINDS = ['vat', 'withholding', 'tot'];

    private const SALES = ['invoice' => 1, 'sales_receipt' => 1, 'credit_note' => -1];

    private const PURCHASES = ['bill' => 1, 'expense' => 1, 'vendor_credit' => -1];

    private const POSTED = ['posted', 'part_paid', 'paid'];

    private const ACCOUNTS = ['vat' => ['vat_payable', 'vat_receivable'], 'withholding' => ['withholding_payable', 'withholding_receivable'], 'tot' => ['tot_payable', null]];

    public function __construct(private readonly PostingService $posting)
    {
    }

    /** @return array<string, mixed> the worksheet of a period */
    public function worksheet(Company $company, string $kind, string $from, string $to): array
    {
        $id = $company->getKey();
        [$payableKey, $receivableKey] = self::ACCOUNTS[$kind];
        if ($kind === 'tot' && ! Account::query()->where('system_key', 'tot_payable')->exists()) {
            throw ValidationException::withMessages(['kind' => ['This company has no turnover tax: add a TOT rate first (its country pack has none).']]);
        }
        $payable = Account::system($payableKey)->getKey();
        $receivable = $receivableKey === null ? $payable : Account::system($receivableKey)->getKey();
        $movement = fn (string $account, string $sign) => (string) DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->where(['l.company_id' => $id, 'l.account_id' => $account])->whereBetween('l.entry_date', [$from, $to])->where('e.source_type', '<>', 'tax_settlement')
            ->selectRaw($sign === 'credit' ? 'coalesce(sum(l.credit - l.debit), 0)::numeric(20,2)::text AS m' : 'coalesce(sum(l.debit - l.credit), 0)::numeric(20,2)::text AS m')
            ->value('m');
        $base = $company->base_currency;
        $output = Money::of($movement($payable, 'credit'), $base);
        $input = $kind === 'vat' ? Money::of($movement($receivable, 'debit'), $base) : Money::zero($base);

        $explained = [];
        if ($kind === 'vat') {
            // Sales: every tax but turnover tax posts to VAT payable. Purchases: only VAT, and only for a VAT-registered
            // company, is reclaimed; otherwise it is part of the cost (ApproveInvoice).
            $sales = $this->vatSide($id, self::SALES, $from, $to, "coalesce(r.kind, '') <> 'tot'");
            $purchases = $this->vatSide($id, self::PURCHASES, $from, $to, $company->vat_registered ? "r.kind = 'vat'" : 'false');
            $explained = [...$sales['entries'], ...$purchases['entries']];
            $sides = ['sales' => $sales, 'purchases' => $purchases];
            $documentsOutput = Money::of($sales['tax'], $base);
            $documentsInput = Money::of($purchases['tax'], $base);
        } elseif ($kind === 'tot') {
            // Sales taxed at a TOT rate post their tax to TOT payable (ApproveInvoice); there is nothing to reclaim.
            $sales = $this->vatSide($id, self::SALES, $from, $to, "r.kind = 'tot'");
            $explained = $sales['entries'];
            $sides = ['sales' => $sales];
            $documentsOutput = Money::of($sales['tax'], $base);
            $documentsInput = Money::zero($base);
        } else {
            $register = $this->withholdingRegister($id, $from, $to, 'out');
            $explained = $register['entries'];
            $sides = ['withheld' => $register, 'withheld_by_customers' => $this->withholdingRegister($id, $from, $to, 'in')];
            $documentsOutput = Money::of($register['tax'], $base);
            $documentsInput = Money::zero($base);
        }
        $other = DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->where('l.company_id', $id)->whereIn('l.account_id', $kind === 'vat' ? [$payable, $receivable] : [$payable])
            ->whereBetween('l.entry_date', [$from, $to])->where('e.source_type', '<>', 'tax_settlement')
            ->whereNotIn('e.id', array_values(array_unique($explained)))
            ->groupBy('e.id', 'e.number', 'e.entry_date', 'e.memo', 'e.source_type')->orderBy('e.entry_date')->orderBy('e.number')
            ->select('e.id', 'e.number', 'e.entry_date', 'e.memo', 'e.source_type')
            ->selectRaw('sum(CASE WHEN l.account_id = ? THEN l.credit - l.debit ELSE 0 END)::numeric(20,2)::text AS output', [$payable])
            ->selectRaw('sum(CASE WHEN l.account_id = ? THEN l.debit - l.credit ELSE 0 END)::numeric(20,2)::text AS input', [$receivable])
            ->limit(500)->get();

        return [
            'kind' => $kind, 'from' => $from, 'to' => $to, 'currency' => $base,
            'output_tax' => $output->toDecimalString(), 'input_tax' => $input->toDecimalString(), 'net' => $output->minus($input)->toDecimalString(),
            ...$sides,
            'other' => [
                'output' => $output->minus($documentsOutput)->toDecimalString(), 'input' => $input->minus($documentsInput)->toDecimalString(),
                'entries' => $other->map(fn ($e) => ['id' => $e->id, 'number' => (int) $e->number, 'date' => $e->entry_date, 'memo' => $e->memo,
                    'source_type' => $e->source_type, 'output' => $e->output, 'input' => $e->input])->values()->all(),
            ],
        ];
    }

    /**
     * File a return for a period that has ended: its figures are built now and kept with it, and the period is then
     * locked for that tax (039).
     *
     * @return string the return's id
     */
    public function file(Company $company, string $kind, string $from, string $to, string $filedOn, ?string $reference, string $userId): string
    {
        return DB::transaction(function () use ($company, $kind, $from, $to, $filedOn, $reference, $userId) {
            DB::select('select pg_advisory_xact_lock(ledger_period_lock_key(?::uuid))', [$company->getKey()]);   // no posting slips in while it is filed
            $overlap = DB::table('tax_returns')->where(['company_id' => $company->getKey(), 'kind' => $kind])
                ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->first(['period_start', 'period_end']);
            if ($overlap !== null) {
                throw ValidationException::withMessages(['period_start' => ["A {$kind} return already covers {$overlap->period_start} to {$overlap->period_end}."]]);
            }
            $sheet = $this->worksheet($company, $kind, $from, $to);
            $id = (string) Str::uuid7();
            DB::table('tax_returns')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'kind' => $kind, 'period_start' => $from, 'period_end' => $to,
                'filed_on' => $filedOn, 'reference' => $reference, 'output_tax' => $sheet['output_tax'], 'input_tax' => $sheet['input_tax'], 'net' => $sheet['net'],
                'figures' => json_encode($sheet, JSON_THROW_ON_ERROR), 'filed_by' => $userId,
            ]);

            return $id;
        });
    }

    /**
     * Settle a filed return on a day after its period: one entry, and the return names it.
     *
     * @return array{entry: array{id: string, number: int}, paid: string}
     */
    public function settle(Company $company, object $return, string $date, ?string $bankAccountId, string $userId): array
    {
        $base = $company->base_currency;
        $output = Money::of((string) $return->output_tax, $base);
        $input = Money::of((string) $return->input_tax, $base);
        $due = $output->minus($input);
        [$payableKey, $receivableKey] = self::ACCOUNTS[$return->kind];
        if ($output->isZero() || $output->isNegative()) {
            throw ValidationException::withMessages(['date' => ['Nothing is due on this return: there is nothing to settle (a credit carries forward).']]);
        }
        $paid = $due->isNegative() ? Money::zero($base) : $due;
        $bank = null;
        if (! $paid->isZero()) {
            $bank = $bankAccountId === null ? null : Account::query()->whereKey($bankAccountId)->whereIn('subtype', ['bank', 'cash', 'mobile_money'])->where('is_active', true)->first();
            if ($bank === null || ($bank->currency !== null && $bank->currency !== $base)) {
                throw ValidationException::withMessages(['bank_account_id' => ["Choose the bank, wallet or cash account the {$paid->toDecimalString()} was paid from, in {$base}."]]);
            }
        }
        $label = match ($return->kind) { 'vat' => 'VAT', 'tot' => 'Turnover tax', default => 'Withholding tax' };
        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $base, '1',
            "{$label} return {$return->period_start} to {$return->period_end} settled" . ($return->reference ? " ({$return->reference})" : ''), 'tax_settlement', $return->id);
        $draft->add(JournalLineDraft::debit(Account::system($payableKey)->getKey(), $output, null, "{$label} due for the period"));
        if ($return->kind === 'vat' && ! $input->isZero()) {
            $offset = $due->isNegative() ? $output : $input;   // a credit beyond the output stays receivable, carried forward
            $draft->add(JournalLineDraft::credit(Account::system($receivableKey)->getKey(), $offset, null, 'Input VAT set against it'));
        }
        if ($bank !== null) {
            $draft->add(JournalLineDraft::credit($bank->getKey(), $paid, null, "Paid to the tax authority"));
        }

        return DB::transaction(function () use ($draft, $userId, $return, $date, $paid) {
            $entry = $this->posting->post($draft, $userId);
            DB::table('tax_returns')->where(['company_id' => $return->company_id, 'id' => $return->id])
                ->update(['settlement_entry_id' => $entry->getKey(), 'settled_on' => $date]);

            return ['entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number], 'paid' => $paid->toDecimalString()];
        });
    }

    /**
     * One side of the VAT return from its documents: by rate (taxable amount and tax, in the base currency) and by
     * document, with the entries they posted. $taxCounts is which lines' tax went to the VAT account.
     *
     * @param array<string, int> $types document type => sign
     * @return array{rates: list<array<string, string>>, documents: list<array<string, mixed>>, taxable: string, tax: string, entries: list<string>}
     */
    private function vatSide(string $companyId, array $types, string $from, string $to, string $taxCounts): array
    {
        $taxCounts = in_array($taxCounts, ["coalesce(r.kind, '') <> 'tot'", "r.kind = 'vat'", "r.kind = 'tot'", 'false'], true) ? $taxCounts : 'false';   // one of the fixed conditions above
        $sign = 'CASE d.type ' . implode(' ', array_map(fn ($t, $s) => "WHEN '{$t}' THEN {$s}", array_keys($types), $types)) . ' END';   // fixed names, not input
        $lines = DB::table('document_lines as dl')
            ->join('documents as d', fn ($j) => $j->on('d.company_id', '=', 'dl.company_id')->on('d.id', '=', 'dl.document_id'))
            ->leftJoin('tax_rates as r', 'r.id', '=', 'dl.tax_rate_id')
            ->where('dl.company_id', $companyId)->whereIn('d.type', array_keys($types))->whereIn('d.status', self::POSTED)
            ->whereBetween('d.issue_date', [$from, $to]);
        $rates = (clone $lines)->groupBy('r.code', 'r.name', 'r.rate', 'r.kind')->orderBy('r.code')
            ->select('r.code', 'r.name', 'r.rate', 'r.kind')
            ->selectRaw("sum(({$sign}) * round(dl.line_subtotal * d.fx_rate, 2))::numeric(20,2)::text AS taxable")
            ->selectRaw("sum(CASE WHEN {$taxCounts} THEN ({$sign}) * round(dl.line_tax * d.fx_rate, 2) ELSE 0 END)::numeric(20,2)::text AS tax")
            ->get();
        $documents = (clone $lines)->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->groupBy('d.id', 'd.type', 'd.number', 'd.issue_date', 'c.name', 'c.tax_id', 'd.journal_entry_id')->orderBy('d.issue_date')->orderBy('d.number')
            ->select('d.id', 'd.type', 'd.number', 'd.issue_date', 'c.name as contact', 'c.tax_id', 'd.journal_entry_id')
            ->selectRaw("sum(({$sign}) * round(dl.line_subtotal * d.fx_rate, 2))::numeric(20,2)::text AS taxable")
            ->selectRaw("sum(CASE WHEN {$taxCounts} THEN ({$sign}) * round(dl.line_tax * d.fx_rate, 2) ELSE 0 END)::numeric(20,2)::text AS tax")
            ->limit(2000)->get();
        $sum = fn ($rows, string $col) => $rows->reduce(fn ($t, $r) => $t->plus((string) $r->{$col}), \Brick\Math\BigDecimal::zero())->toScale(2)->__toString();

        return [
            'rates' => $rates->map(fn ($r) => ['code' => $r->code ?? 'NONE', 'name' => $r->name ?? 'No tax rate', 'rate' => $r->rate === null ? null : (string) $r->rate,
                'kind' => $r->kind, 'taxable' => $r->taxable, 'tax' => $r->tax])->values()->all(),
            'documents' => $documents->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'number' => $d->number, 'date' => $d->issue_date, 'contact' => $d->contact,
                'tax_id' => $d->tax_id, 'taxable' => $d->taxable, 'tax' => $d->tax])->values()->all(),
            'taxable' => $sum($rates, 'taxable'), 'tax' => $sum($rates, 'tax'),
            'entries' => $documents->pluck('journal_entry_id')->filter()->values()->all(),
        ];
    }

    /**
     * The withholding register: payments to suppliers with tax withheld ('out', the return), or payments from customers
     * who withheld tax ('in', a credit against profit tax, kept with their certificates).
     *
     * @return array{payments: list<array<string, mixed>>, gross: string, tax: string, entries: list<string>}
     */
    private function withholdingRegister(string $companyId, string $from, string $to, string $direction): array
    {
        $rows = DB::table('payments as p')->leftJoin('contacts as c', 'c.id', '=', 'p.contact_id')
            ->where(['p.company_id' => $companyId, 'p.direction' => $direction === 'in' ? 'in' : 'out', 'p.status' => 'posted'])->where('p.withholding', '>', 0)
            ->whereBetween('p.payment_date', [$from, $to])->orderBy('p.payment_date')
            ->select('p.id', 'p.payment_date', 'p.reference', 'p.withholding_certificate', 'c.name as contact', 'c.tax_id', 'p.journal_entry_id')
            ->selectRaw('round((p.amount + p.withholding) * p.fx_rate, 2)::numeric(20,2)::text AS gross')
            ->selectRaw('round(p.withholding * p.fx_rate, 2)::numeric(20,2)::text AS withheld')
            ->limit(2000)->get();
        $sum = fn (string $col) => $rows->reduce(fn ($t, $r) => $t->plus((string) $r->{$col}), \Brick\Math\BigDecimal::zero())->toScale(2)->__toString();

        return [
            'payments' => $rows->map(fn ($p) => ['id' => $p->id, 'date' => $p->payment_date, 'contact' => $p->contact, 'tax_id' => $p->tax_id,
                'reference' => $p->reference, 'certificate' => $p->withholding_certificate, 'gross' => $p->gross, 'withheld' => $p->withheld])->values()->all(),
            'gross' => $sum('gross'), 'tax' => $sum('withheld'),
            'entries' => $rows->pluck('journal_entry_id')->filter()->values()->all(),
        ];
    }
}
