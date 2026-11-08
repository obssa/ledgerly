<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Compliance\Jobs\SubmitEInvoice;
use App\Domain\Compliance\Models\EInvoiceSubmission;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\InvoicePostingBuilder;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approve = post to the ledger + queue tax-authority clearance, in one transaction.
 * The clearance job runs after commit, retries on its own, and stores the registration number and QR.
 */
final class ApproveInvoice
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly InvoicePostingBuilder $builder,
        private readonly PostingService $posting,
        private readonly ConnectorRegistry $connectors,
    ) {
    }

    public function handle(Document $invoice, ?string $userId = null): Document
    {
        if (! in_array($invoice->type, ['invoice', 'credit_note'], true) || ! $invoice->isEditable()) {
            throw new LedgerException("Document {$invoice->number} cannot be approved in status {$invoice->status}.");
        }

        $company = $this->current->get();

        $posted = DB::transaction(function () use ($invoice, $company, $userId) {
            $entry = $this->posting->post($this->journalDraftFor($invoice->load('lines')), $userId);

            $invoice->status = 'posted';
            $invoice->journal_entry_id = $entry->getKey();
            $invoice->save();

            if ($connector = $this->connectors->forCountry($company->country_code)) {
                $submission = EInvoiceSubmission::query()->create([
                    'document_id' => $invoice->getKey(),
                    'authority' => $connector->authority(),
                    'status' => 'queued',
                ]);
                SubmitEInvoice::dispatch($company->getKey(), $submission->getKey())->afterCommit();
            }

            return $invoice;
        });

        // Read back after commit, so a clearance that already ran (sync queue) is included.
        return $posted->fresh(['lines', 'einvoiceSubmissions']);
    }

    /** The balanced journal draft for an invoice or credit note as its lines stand now (lines must be loaded). */
    public function journalDraftFor(Document $invoice): JournalDraft
    {
        $company = $this->current->get();

        // Each tax kind posts to its own liability account (VAT payable, turnover tax payable ...).
        $kinds = DB::table('tax_rates')->whereIn('id', $invoice->lines->pluck('tax_rate_id')->filter())->pluck('kind', 'id');
        $taxAccount = fn (?string $rateId) => $rateId === null ? null : Account::system(match ($kinds[$rateId] ?? 'vat') {
            'tot' => 'tot_payable',
            default => 'vat_payable',
        })->getKey();

        $lines = $invoice->lines->map(fn ($line) => [
            'income_account_id' => $line->account_id,
            'subtotal' => Money::of($line->line_subtotal, $invoice->currency),
            'tax' => Money::of($line->line_tax, $invoice->currency),
            'tax_account_id' => $taxAccount($line->tax_rate_id),
        ])->all();

        return $this->builder->build(
            companyId: $company->getKey(),
            documentId: $invoice->getKey(),
            documentNumber: $invoice->number,
            issueDate: DateTimeImmutable::createFromInterface($invoice->issue_date),
            baseCurrency: $company->base_currency,
            currency: $invoice->currency,
            fxRate: (string) $invoice->fx_rate,
            customerId: $invoice->contact_id,
            receivableAccountId: Account::system('accounts_receivable')->getKey(),
            lines: $lines,
            isCreditNote: $invoice->type === 'credit_note',
        );
    }
}
