<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 9a, construction: retention a customer holds back on an invoice until the work is complete (050). Holding it
 * moves that part from accounts receivable to retention receivable, so the invoice owes less now; releasing it moves it
 * back and the invoice is owed again. Each step is one entry through the posting service; the database keeps the
 * invoice's open amount in step with it.
 */
final class Retention
{
    public const ACCOUNT_KEY = 'retention_receivable';

    public function __construct(private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    /** Holds retention on a posted invoice still owed: a given amount, or a share of the invoice total. */
    public function hold(Company $company, Document $invoice, string $amount, string $heldOn, string $userId): void
    {
        $this->assertInvoice($company, $invoice);
        DB::transaction(function () use ($company, $invoice, $amount, $heldOn, $userId) {
            $id = (string) Str::uuid();
            $base = (string) $company->base_currency;
            $money = Money::of($amount, $base);
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($heldOn), $base, $base, '1', "Retention held on {$invoice->number}", 'retention', $id);
            $draft->add(JournalLineDraft::debit($this->account($company)->getKey(), $money, null, "Retention held on {$invoice->number}", $invoice->contact_id));
            $draft->add(JournalLineDraft::credit(Account::system('accounts_receivable')->getKey(), $money, null, "Retention held on {$invoice->number}", $invoice->contact_id));
            $entry = $this->posting->post($draft, $userId);
            DB::table('invoice_retentions')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'document_id' => $invoice->getKey(), 'amount' => $money->toDecimalString(),
                'held_on' => $heldOn, 'held_entry_id' => $entry->getKey(), 'held_by' => $userId,
            ]);
            $this->audit->record($company->getKey(), 'retention_held', 'invoice', $invoice->getKey(), null,
                ['amount' => $money->toDecimalString(), 'held_on' => $heldOn, 'entry' => $entry->getKey()], userId: $userId);
        });
    }

    /** Releases everything still held on the invoice: the customer now owes it. */
    public function release(Company $company, Document $invoice, string $releasedOn, string $userId): string
    {
        return DB::transaction(function () use ($company, $invoice, $releasedOn, $userId) {
            $held = DB::table('invoice_retentions')->where(['company_id' => $company->getKey(), 'document_id' => $invoice->getKey()])
                ->whereNull('released_on')->orderBy('held_on')->lockForUpdate()->get();
            if ($held->isEmpty()) {
                throw ValidationException::withMessages(['retention' => ['Nothing is held on this invoice.']]);
            }
            if ($held->contains(fn ($r) => $r->held_on > $releasedOn)) {
                throw ValidationException::withMessages(['released_on' => ['Retention is released on or after the day it was held.']]);
            }
            $base = (string) $company->base_currency;
            $total = Money::of('0', $base);
            foreach ($held as $r) {
                $money = Money::of((string) $r->amount, $base);
                $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($releasedOn), $base, $base, '1', "Retention released on {$invoice->number}", 'retention', $r->id);
                $draft->add(JournalLineDraft::debit(Account::system('accounts_receivable')->getKey(), $money, null, "Retention released on {$invoice->number}", $invoice->contact_id));
                $draft->add(JournalLineDraft::credit($this->account($company)->getKey(), $money, null, "Retention released on {$invoice->number}", $invoice->contact_id));
                $entry = $this->posting->post($draft, $userId);
                DB::table('invoice_retentions')->where('id', $r->id)->update(['released_on' => $releasedOn, 'released_entry_id' => $entry->getKey(), 'released_by' => $userId]);
                $total = $total->plus($money);
            }
            $this->audit->record($company->getKey(), 'retention_released', 'invoice', $invoice->getKey(), null,
                ['amount' => $total->toDecimalString(), 'released_on' => $releasedOn, 'holds' => $held->count()], userId: $userId);

            return $total->toDecimalString();
        });
    }

    /** @return list<array{id: string, amount: string, held_on: string, released_on: ?string}> */
    public function of(string $documentId): array
    {
        return DB::table('invoice_retentions')->where('document_id', $documentId)->orderBy('held_on')->orderBy('created_at')
            ->get(['id', 'amount', 'held_on', 'released_on'])
            ->map(fn ($r) => ['id' => $r->id, 'amount' => (string) $r->amount, 'held_on' => (string) $r->held_on, 'released_on' => $r->released_on])->all();
    }

    private function assertInvoice(Company $company, Document $invoice): void
    {
        if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['posted', 'part_paid'], true)) {
            throw ValidationException::withMessages(['invoice' => ['Retention is held on a posted invoice that still owes something.']]);
        }
        if ($invoice->currency !== $company->base_currency) {
            throw ValidationException::withMessages(['invoice' => ["Retention is held on invoices in {$company->base_currency} only."]]);
        }
    }

    /**
     * The company's retention receivable account, made the first time it is needed: an asset of the receivable kind
     * (so no manual journal reaches it), next to accounts receivable in the chart.
     */
    private function account(Company $company): Account
    {
        $existing = Account::query()->where('system_key', self::ACCOUNT_KEY)->first();
        if ($existing !== null) {
            return $existing;
        }
        $taken = DB::table('accounts')->where('company_id', $company->getKey())->pluck('code')->all();
        $code = collect(['1220', ...array_map(fn ($n) => (string) $n, range(1221, 1299))])->first(fn ($c) => ! in_array($c, $taken, true)) ?? 'RET-' . Str::upper(Str::random(4));
        $account = new Account();
        $account->forceFill(['company_id' => $company->getKey(), 'code' => $code, 'name' => 'Retention receivable', 'type' => 'asset',
            'subtype' => 'receivable', 'system_key' => self::ACCOUNT_KEY, 'is_active' => true])->save();

        return $account;
    }
}
