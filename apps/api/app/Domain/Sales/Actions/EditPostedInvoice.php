<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\Jobs\SubmitEInvoice;
use App\Domain\Compliance\Models\EInvoiceSubmission;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\PeriodLocked;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a posted invoice or credit note without losing anything:
 *
 *   1. the document as it stands is saved to document_versions (version 1, 2, 3 ...)
 *   2. its journal entry is reversed, never edited or deleted
 *   3. the corrected document is posted as a new journal entry
 *   4. an audit record stores who, when, why, every changed field and the three entry ids
 *
 * All in one transaction. While the fiscal year is open, anyone with `transactions.edit` may edit.
 * In a closed period only a role with `periods.reopen` (admin) may, and the reopen and re-lock are
 * themselves audited. The database refuses an edit that skips step 1 (005_history.sql), an edit of a
 * document that has payments, and an edit of a document cleared by the tax authority.
 */
final class EditPostedInvoice
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly SaveInvoiceDraft $calculator,
        private readonly ApproveInvoice $approver,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Document $invoice, array $data, string $reason, string $userId, Membership $membership): Document
    {
        if (! in_array($invoice->type, ['invoice', 'credit_note', 'bill'], true) || ! $invoice->isPosted()) {
            throw new LedgerException("Only posted invoices, credit notes and bills are edited this way ({$invoice->number} is {$invoice->status}).");
        }
        if ($invoice->opening) {
            throw new LedgerException("{$invoice->number} was brought in from the old books: void it and enter it again with the right amount.");
        }
        if (DB::table('stock_moves')->where(['company_id' => $invoice->company_id, 'document_id' => $invoice->getKey()])->exists()) {
            throw new LedgerException("{$invoice->number} moved stock: void it and enter it again, so the bin card stays exact.");
        }
        if ($invoice->status !== 'posted') {
            throw new LedgerException("{$invoice->number} has payments allocated to it. Remove the allocation first, then edit.");
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new LedgerException('A reason is required to edit a posted document.');
        }

        // Tax-authority rule: a registered e-invoice is corrected with a credit note and a new invoice.
        $cleared = EInvoiceSubmission::query()->where('document_id', $invoice->getKey())->where('status', 'cleared')->exists();
        if ($cleared) {
            throw new LedgerException("{$invoice->number} is registered with the tax authority; issue a credit note and a new invoice instead.");
        }
        if (EInvoiceSubmission::query()->where('document_id', $invoice->getKey())->where('status', 'submitted')->exists()) {
            throw new LedgerException("{$invoice->number} is being registered with the tax authority right now; try again when it has an answer.");
        }

        if (isset($data['number']) && $data['number'] !== '' && $data['number'] !== $invoice->number) {
            throw ValidationException::withMessages(['number' => ["{$invoice->number} is posted; its number never changes."]]);
        }

        $company = $this->current->get();
        // A posted credit note keeps its invoice's salesperson; an invoice's may change to another active member.
        $data['salesperson_id'] = $invoice->type === 'invoice' ? $this->calculator->salesperson($data, $invoice) : $invoice->salesperson_id;
        $calc = $this->calculator->calculate($data);
        $calc['lock_version'] = $data['lock_version'] ?? null;

        // Closed period: admin only.
        $closedUntil = $company->books_closed_until?->format('Y-m-d');
        $earliest = min($invoice->issue_date->format('Y-m-d'), $calc['header']['issue_date']);
        $reopen = $closedUntil !== null && $earliest <= $closedUntil;
        if ($reopen && ! $membership->can('periods.reopen')) {
            throw new PeriodLocked("Books are closed until {$closedUntil}. Only an admin can edit {$invoice->number}.");
        }

        $edited = DB::transaction(function () use ($invoice, $calc, $reason, $userId, $company, $reopen, $closedUntil) {
            $invoice = Document::query()->with('lines')->lockForUpdate()->findOrFail($invoice->getKey());
            $invoice->assertVersion(isset($calc['lock_version']) ? (int) $calc['lock_version'] : null);
            // Retention held is not part of what was paid: the invoice is edited once it is released (050).
            if (DB::table('invoice_retentions')->where('document_id', $invoice->getKey())->whereNull('released_on')->exists()) {
                throw new \App\Domain\Ledger\Exceptions\LedgerException("{$invoice->number} holds retention: release it before editing the invoice.");
            }
            $paid = Money::of($invoice->total, $invoice->currency)->minus(Money::of($invoice->amount_due, $invoice->currency));
            if (! $paid->isZero() && $calc['header']['currency'] !== $invoice->currency) {
                throw new LedgerException("{$invoice->number} is partly paid; its currency cannot change.");
            }
            $paid = Money::of($paid->amount, $calc['header']['currency']);
            $newTotal = Money::of($calc['totals']['total'], $calc['header']['currency']);
            if ($newTotal->minus($paid)->isNegative()) {
                throw new LedgerException("The new total is less than the {$paid} already paid; refund or reallocate the payment first.");
            }

            if ($reopen) {
                $this->audit->record($company->getKey(), 'period_reopened', 'company', $company->getKey(),
                    ['books_closed_until' => $closedUntil], ['books_closed_until' => null], "To edit {$invoice->number}: {$reason}", $userId);
                DB::table('companies')->where('id', $company->getKey())->update(['books_closed_until' => null]);
            }

            // 1. Save the current version (the database requires this before any change).
            $version = (int) DocumentVersion::query()->where('document_id', $invoice->getKey())->max('version') + 1;
            $before = $this->summary($invoice->toArray());
            DocumentVersion::query()->create([
                'document_id' => $invoice->getKey(),
                'version' => $version,
                'journal_entry_id' => $invoice->journal_entry_id,
                'snapshot' => $invoice->toArray(), // header and lines exactly as they were
                'reason' => $reason,
                'edited_by' => $userId,
            ]);

            // 2. Reverse the original entry on its own date.
            $oldEntryId = $invoice->journal_entry_id;
            $reversal = $this->posting->reverse($oldEntryId, DateTimeImmutable::createFromInterface($invoice->issue_date), $userId);

            // 3. Apply the corrected lines and post the corrected entry.
            SaveInvoiceDraft::writeLines($invoice, $calc['lines']);
            $invoice->fill($calc['header']);
            $invoice->setRelation('lines', $invoice->lines()->get());
            $corrected = $this->posting->post($this->approver->journalDraftFor($invoice), $userId);

            $invoice->forceFill($calc['totals']);
            $invoice->amount_due = $newTotal->minus($paid)->toDecimalString();
            $invoice->journal_entry_id = $corrected->getKey();
            $invoice->save();

            // 4. Audit: every changed field, the reason and the three linked entries.
            $changes = AuditLogger::diff($before, $this->summary($invoice->fresh('lines')->toArray()));
            $this->audit->record($company->getKey(), 'edited_after_posting', $invoice->type, $invoice->getKey(),
                array_map(fn (array $c) => $c['old'], $changes),
                [
                    'changes' => $changes,
                    'version_saved' => $version,
                    'original_entry_id' => $oldEntryId,
                    'reversal_entry_id' => $reversal->getKey(),
                    'corrected_entry_id' => $corrected->getKey(),
                ],
                $reason, $userId);

            // A submission not yet cleared (queued, failed or rejected) is re-sent with the corrected data.
            $pending = EInvoiceSubmission::query()->where('document_id', $invoice->getKey())->first();
            if ($pending && $pending->status !== 'cleared') {
                $pending->forceFill(['status' => 'queued', 'last_error' => null])->save(); // failed or rejected -> queued
                SubmitEInvoice::dispatch($company->getKey(), $pending->getKey())->afterCommit();
            }

            if ($reopen) {
                DB::table('companies')->where('id', $company->getKey())->update(['books_closed_until' => $closedUntil]);
                $this->audit->record($company->getKey(), 'period_relocked', 'company', $company->getKey(),
                    ['books_closed_until' => null], ['books_closed_until' => $closedUntil], "After editing {$invoice->number}", $userId);
            }

            return $invoice;
        });

        return $edited->fresh(['lines', 'einvoiceSubmissions']);
    }

    /** The fields compared before and after an edit, lines included. */
    private function summary(array $doc): array
    {
        $fields = ['contact_id', 'number', 'issue_date', 'due_date', 'currency', 'fx_rate', 'notes', 'salesperson_id', 'subtotal', 'tax_total', 'total', 'amount_due'];
        $out = array_intersect_key($doc, array_flip($fields));
        $out['lines'] = array_map(
            fn (array $l) => array_intersect_key($l, array_flip(['line_no', 'description', 'quantity', 'unit_price', 'discount_rate', 'tax_rate_id', 'account_id', 'line_subtotal', 'line_tax'])),
            $doc['lines'] ?? [],
        );

        return $out;
    }
}
