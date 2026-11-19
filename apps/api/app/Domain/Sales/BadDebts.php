<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: bad debts (078). What an invoice still owes, or part of it, is written off: one entry, Dr Bad debts,
 * Cr Accounts receivable (the customer), in the invoice's currency at its own rate, so nothing is left in exchange
 * differences; the invoice owes that much less. It is not a credit note: nothing goes to the customer or the tax
 * authority. Reversed (the customer paid after all), the entry is reversed and the invoice is owed again.
 */
final class BadDebts
{
    public function __construct(private readonly CurrentCompany $current, private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, mixed> the write-off */
    public function writeOff(Document $invoice, ?string $amount, string $date, string $reason, string $userId): array
    {
        $company = $this->current->get();

        return DB::transaction(function () use ($invoice, $amount, $date, $reason, $userId, $company) {
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['posted', 'part_paid'], true)) {
                throw new LedgerException('A bad debt is written off a posted invoice that still owes something.');
            }
            $owed = (string) $invoice->amount_due;
            $amount ??= $owed;
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $owed, 2) > 0) {
                throw ValidationException::withMessages(['amount' => ["{$invoice->number} still owes {$owed}: write off up to that."]]);
            }
            if ($date < $invoice->issue_date->format('Y-m-d') || $date > $this->current->today()) {
                throw ValidationException::withMessages(['date' => ["Dated from the invoice's date ({$invoice->issue_date->format('Y-m-d')}) to today."]]);
            }

            $id = (string) Str::uuid7();
            $base = $company->base_currency;
            $txn = Money::of($amount, $invoice->currency);
            $size = $invoice->currency === $base ? Money::of($amount, $base) : $txn->convertTo($base, (string) $invoice->fx_rate)->roundToCurrency();
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $invoice->currency, (string) $invoice->fx_rate,
                mb_substr("Bad debt written off: {$invoice->number}", 0, 255), 'bad_debt', $id);
            $draft->add(JournalLineDraft::debit(Account::system('bad_debt')->getKey(), $size, $txn, "Bad debt, {$invoice->number}", (string) $invoice->contact_id));
            $draft->add(JournalLineDraft::credit(Account::system('accounts_receivable')->getKey(), $size, $txn, "Written off, {$invoice->number}", (string) $invoice->contact_id));
            $entry = $this->posting->post($draft, $userId);

            DB::table('invoice_write_offs')->insert(['id' => $id, 'company_id' => $company->getKey(), 'document_id' => $invoice->getKey(), 'amount' => $amount,
                'written_off_on' => $date, 'reason' => $reason, 'entry_id' => $entry->getKey(), 'written_off_by' => $userId]);
            $this->audit->record($company->getKey(), 'bad_debt_written_off', 'invoice', $invoice->getKey(), ['amount_due' => $owed],
                ['write_off_id' => $id, 'amount' => $amount, 'date' => $date, 'journal_entry' => $entry->number, 'amount_due' => bcsub($owed, $amount, 2)], $reason, $userId);

            return $this->find($id);
        });
    }

    /** The customer paid after all: the write-off's entry is reversed on $date and the invoice is owed again. */
    public function reverse(string $writeOffId, string $date, ?string $reason, string $userId): array
    {
        $company = $this->current->get();

        return DB::transaction(function () use ($writeOffId, $date, $reason, $userId, $company) {
            $w = DB::table('invoice_write_offs')->where('id', $writeOffId)->lockForUpdate()->first() ?? abort(404);
            if ($w->reversed_on !== null) {
                throw new LedgerException('This write-off is already reversed.');
            }
            if ($date < (string) $w->written_off_on || $date > $this->current->today()) {
                throw ValidationException::withMessages(['date' => ["Dated from the write-off ({$w->written_off_on}) to today."]]);
            }
            $reversal = $this->posting->reverse((string) $w->entry_id, new DateTimeImmutable($date), $userId, laterEvent: true);
            DB::table('invoice_write_offs')->where('id', $writeOffId)->update(['reversed_on' => $date, 'reversal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId, 'reversal_reason' => $reason]);
            $this->audit->record($company->getKey(), 'bad_debt_reversed', 'invoice', (string) $w->document_id, ['write_off_id' => $writeOffId, 'reversed' => false],
                ['reversed_on' => $date, 'journal_entry' => $reversal->number], $reason, $userId);

            return $this->find($writeOffId);
        });
    }

    /** @return list<array<string, mixed>> an invoice's write-offs, the latest first */
    public function forInvoice(string $invoiceId): array
    {
        return DB::table('invoice_write_offs')->where('document_id', $invoiceId)->orderByDesc('written_off_on')->orderByDesc('created_at')->get()
            ->map(fn ($w) => (array) $w)->all();
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return (array) (DB::table('invoice_write_offs')->where('id', $id)->first() ?? abort(404));
    }
}
