<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Inventory\DocumentStock;
use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\PeriodLocked;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Support\StateMachine;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The invoice status changes that are not a posting of their own: submit for approval, reject, void.
 * Each one locks the document, asks the state machine, makes the change and writes an audit record
 * (from, to, who, why) in the request's transaction. Posting is ApproveInvoice; edits are EditPostedInvoice.
 */
final class InvoiceWorkflow
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly StateMachine $states,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {
    }

    public function submitForApproval(Document $invoice, string $userId): Document
    {
        return $this->move($invoice, 'awaiting_approval', 'submitted_for_approval', null, $userId);
    }

    public function reject(Document $invoice, string $reason, string $userId): Document
    {
        return $this->move($invoice, 'draft', 'approval_rejected', $reason, $userId);
    }

    /**
     * Void: draft -> void (nothing was posted), or posted -> void with a reversing entry on the invoice date.
     * A part-paid or paid invoice cannot be voided (remove the payment first), nor one cleared by the tax
     * authority (issue a credit note). In a closed period only an admin can void.
     */
    public function void(Document $invoice, string $reason, string $userId, Membership $membership): Document
    {
        $company = $this->current->get();

        return DB::transaction(function () use ($invoice, $reason, $userId, $membership, $company) {
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->states->assert('invoice', $invoice->status, 'void', $invoice->label());

            $reversalId = null;
            if ($invoice->status === 'posted') {
                $closedUntil = $company->books_closed_until?->format('Y-m-d');
                $inClosedPeriod = $closedUntil !== null && $invoice->issue_date->format('Y-m-d') <= $closedUntil;
                if ($inClosedPeriod && ! $membership->can('periods.reopen')) {
                    throw new PeriodLocked("Books are closed until {$closedUntil}. Only an admin can void {$invoice->number}.");
                }
                if ($invoice->einvoiceSubmissions()->where('status', 'cleared')->exists()) {
                    throw new LedgerException("{$invoice->number} is registered with the tax authority; issue a credit note instead.");
                }

                // In a closed period the reversal is dated the first open day, so the closed period's figures stay as reported.
                $date = $inClosedPeriod
                    ? (new DateTimeImmutable($closedUntil))->modify('+1 day')
                    : DateTimeImmutable::createFromInterface($invoice->issue_date);
                $reversalId = $this->posting->reverse($invoice->journal_entry_id, $date, $userId)->getKey();
                app(DocumentStock::class)->onVoid($invoice, $company, $date, $userId);   // goods back, or out again (025)
            }

            $from = $invoice->status;
            $invoice->status = 'void';
            $invoice->save();

            $this->audit->record($company->getKey(), 'voided', $invoice->type, $invoice->getKey(),
                ['status' => $from], ['status' => 'void', 'reversal_entry_id' => $reversalId], $reason, $userId);

            return $invoice->refresh();
        });
    }

    private function move(Document $invoice, string $to, string $action, ?string $reason, string $userId): Document
    {
        return DB::transaction(function () use ($invoice, $to, $action, $reason, $userId) {
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->states->assert('invoice', $invoice->status, $to, $invoice->label());

            $from = $invoice->status;
            $invoice->status = $to;
            $invoice->save();

            $this->audit->record($this->current->id(), $action, $invoice->type, $invoice->getKey(),
                ['status' => $from], ['status' => $to], $reason, $userId);

            return $invoice->refresh();
        });
    }
}
