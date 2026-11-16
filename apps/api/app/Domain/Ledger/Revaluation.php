<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Month-end revaluation of foreign-currency balances (IAS 21: monetary items at the closing rate). At a date, each
 * foreign-currency bank or wallet balance and each open foreign-currency invoice, credit note, bill and supplier credit
 * is valued at that day's rate; the difference from its value in the books is an unrealised exchange gain or loss. The
 * entry is reversed the next day, so documents keep their own rates and every month starts again from them (034).
 *
 * What a document owed on the date: its amount due now, plus what payments dated after the date and credits applied
 * after it took off. (A payment dated before the date but reversed after it is not added back: rare, and the next
 * month's run is right again.)
 */
final class Revaluation
{
    public function __construct(private readonly ExchangeRates $rates, private readonly PostingService $posting)
    {
    }

    /**
     * @param array<string, string> $given closing rates entered for the run (base per unit), by currency
     * @return array{as_of: string, rates: array<string, string>, lines: list<array<string, mixed>>, net: string}
     */
    public function preview(Company $company, string $asOf, array $given = []): array
    {
        $base = $company->base_currency;
        $id = $company->getKey();
        $items = [];

        foreach (DB::table('accounts')->where('company_id', $id)->whereIn('subtype', ['bank', 'mobile_money', 'cash'])
            ->whereNotNull('currency')->where('currency', '<>', $base)->orderBy('code')->get(['id', 'code', 'name', 'currency']) as $a) {
            $sums = DB::table('journal_lines')->where(['company_id' => $id, 'account_id' => $a->id])->where('entry_date', '<=', $asOf)
                ->selectRaw('coalesce(sum(amount_txn), 0)::numeric(20,2)::text AS txn, coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS base')->first();
            if ($sums->txn === '0.00' && $sums->base === '0.00') {
                continue;
            }
            $items[] = ['kind' => 'account', 'account_id' => $a->id, 'contact_id' => null, 'label' => "{$a->code} {$a->name}", 'document_id' => null,
                'currency' => $a->currency, 'amount' => $sums->txn, 'booked' => $sums->base, 'sign' => 1];
        }

        $receivable = Account::system('accounts_receivable')->getKey();
        $payable = Account::system('accounts_payable')->getKey();
        $sides = ['invoice' => [$receivable, 1], 'credit_note' => [$receivable, -1], 'bill' => [$payable, -1], 'vendor_credit' => [$payable, 1]];
        $docs = DB::table('documents')->where('company_id', $id)->whereIn('type', array_keys($sides))->whereIn('status', ['posted', 'part_paid', 'paid'])
            ->where('currency', '<>', $base)->where('issue_date', '<=', $asOf)->orderBy('issue_date')->orderBy('number')
            ->get(['id', 'type', 'number', 'contact_id', 'currency', 'fx_rate', 'amount_due']);
        $later = $this->takenOffAfter($id, $asOf, $docs->pluck('id')->all());
        foreach ($docs as $d) {
            $due = Money::of((string) $d->amount_due, $d->currency)->plus(Money::of($later[$d->id] ?? '0', $d->currency));
            if ($due->isZero()) {
                continue;
            }
            [$account, $sign] = $sides[$d->type];
            $items[] = ['kind' => $d->type, 'account_id' => $account, 'contact_id' => $d->contact_id, 'label' => $d->number ?? $d->type, 'document_id' => $d->id,
                'currency' => $d->currency, 'amount' => $due->toDecimalString(), 'booked' => $due->convertTo($base, (string) $d->fx_rate)->toDecimalString(), 'sign' => $sign];
        }

        $rates = [];
        foreach (array_unique(array_column($items, 'currency')) as $currency) {
            $found = isset($given[$currency]) ? null : $this->rates->on($id, $currency, $asOf);
            $rate = $given[$currency] ?? ($found['rate'] ?? null);
            if ($rate === null || $rate === '') {
                throw ValidationException::withMessages(["rates.{$currency}" => ["Enter the {$currency} rate for {$asOf}: there is none in the week before it."]]);
            }
            $rates[$currency] = (string) $rate;
        }

        $net = Money::zero($base);
        $lines = [];
        foreach ($items as $item) {
            $restated = Money::of($item['amount'], $item['currency'])->convertTo($base, $rates[$item['currency']]);
            $booked = Money::of($item['booked'], $base);
            // In the ledger's terms (debit minus credit): receivables and bank balances as they are, payables turned.
            $difference = $item['kind'] === 'account' ? $restated->minus($booked) : ($item['sign'] > 0 ? $restated->minus($booked) : $booked->minus($restated));
            if ($difference->isZero()) {
                continue;
            }
            $net = $net->plus($difference);
            $lines[] = [...array_diff_key($item, ['sign' => 1]), 'rate' => $rates[$item['currency']], 'restated' => $restated->toDecimalString(),
                'difference' => $difference->toDecimalString()];
        }

        return ['as_of' => $asOf, 'rates' => $rates, 'lines' => $lines, 'net' => $net->toDecimalString()];
    }

    /** Post the revaluation dated $asOf and its reversal the next day, and keep the run (034). */
    public function run(Company $company, string $asOf, array $given, string $userId): array
    {
        return DB::transaction(function () use ($company, $asOf, $given, $userId) {
            if (DB::table('fx_revaluations')->where(['company_id' => $company->getKey(), 'as_of' => $asOf])->exists()) {
                throw ValidationException::withMessages(['as_of' => ["Foreign balances are already revalued at {$asOf}."]]);
            }
            $preview = $this->preview($company, $asOf, $given);
            if ($preview['lines'] === []) {
                throw ValidationException::withMessages(['as_of' => ["Nothing to revalue at {$asOf}: every foreign balance is already at the day's rate, or there is none."]]);
            }
            $base = $company->base_currency;
            $id = (string) Str::uuid7();
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($asOf), $base, $base, '1', "Revaluation of foreign balances at {$asOf}", 'revaluation', $id);
            foreach ($preview['lines'] as $l) {
                $d = Money::of($l['difference'], $base);
                $draft->add($d->isNegative()
                    ? JournalLineDraft::credit($l['account_id'], $d->negated(), Money::zero($base), "Revaluation {$l['label']} at {$l['rate']}", $l['contact_id'])
                    : JournalLineDraft::debit($l['account_id'], $d, Money::zero($base), "Revaluation {$l['label']} at {$l['rate']}", $l['contact_id']));
            }
            $net = Money::of($preview['net'], $base);
            if (! $net->isZero()) {
                $fx = Account::system('fx_gain_loss')->getKey();
                $draft->add($net->isNegative()
                    ? JournalLineDraft::debit($fx, $net->negated(), Money::zero($base), 'Unrealised exchange loss')
                    : JournalLineDraft::credit($fx, $net, Money::zero($base), 'Unrealised exchange gain'));
            }
            $entry = $this->posting->post($draft, $userId);
            $reversal = $this->posting->reverse($entry->getKey(), (new DateTimeImmutable($asOf))->modify('+1 day'), $userId);

            DB::table('fx_revaluations')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'as_of' => $asOf, 'rates' => json_encode((object) $preview['rates'], JSON_THROW_ON_ERROR),
                'lines' => json_encode($preview['lines'], JSON_THROW_ON_ERROR), 'net' => $preview['net'],
                'journal_entry_id' => $entry->getKey(), 'reversal_entry_id' => $reversal->getKey(), 'created_by' => $userId,
            ]);

            return [...$preview, 'id' => $id, 'entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number],
                'reversal' => ['id' => $reversal->getKey(), 'number' => (int) $reversal->number, 'date' => (new DateTimeImmutable($asOf))->modify('+1 day')->format('Y-m-d')]];
        });
    }

    /** @param list<string> $ids @return array<string, string> per document, what payments and credits after the date took off */
    private function takenOffAfter(string $company, string $asOf, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $add = function (string $doc, string $amount) use (&$out) {
            $out[$doc] = (string) \Brick\Math\BigDecimal::of($out[$doc] ?? '0')->plus($amount);
        };
        foreach (DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where(['pa.company_id' => $company, 'p.status' => 'posted'])->where('p.payment_date', '>', $asOf)->whereIn('pa.document_id', $ids)
            ->groupBy('pa.document_id')->select('pa.document_id')->selectRaw('sum(pa.amount)::text AS n')->get() as $r) {
            $add($r->document_id, $r->n);
        }
        foreach (['invoice_id', 'credit_note_id'] as $side) {
            foreach (DB::table('credit_applications')->where('company_id', $company)->whereDate('created_at', '>', $asOf)->whereIn($side, $ids)
                ->groupBy($side)->select($side)->selectRaw('sum(amount)::text AS n')->get() as $r) {
                $add($r->{$side}, $r->n);
            }
        }

        return $out;
    }
}
