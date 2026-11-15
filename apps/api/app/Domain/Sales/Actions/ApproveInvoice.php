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
use App\Domain\Sales\DocumentNumbers;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\InvoicePostingBuilder;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Support\StateMachine;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approve = post to the ledger + queue tax-authority clearance, in one transaction.
 * State machine: draft or awaiting_approval -> posted.
 *
 * The one optional approval step: when the company sets settings.approval.invoice_above, an invoice
 * above that amount cannot be approved by the person who created it.
 *
 * The clearance job leaves only after the request's transaction commits, retries on its own, and stores
 * the registration number and QR. The response therefore shows the submission as queued; the client
 * reads the invoice again (or listens for the event) to see it cleared.
 */
final class ApproveInvoice
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly InvoicePostingBuilder $builder,
        private readonly PostingService $posting,
        private readonly ConnectorRegistry $connectors,
        private readonly StateMachine $states,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    public function handle(Document $invoice, ?string $userId = null, ?int $lockVersion = null): Document
    {
        if (! in_array($invoice->type, ['invoice', 'credit_note'], true)) {
            throw new LedgerException("Document {$invoice->label()} is not an invoice or credit note.");
        }

        $company = $this->current->get();

        return DB::transaction(function () use ($invoice, $company, $userId, $lockVersion) {
            // Lock the row: two people approving at once must not post twice.
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $invoice->assertVersion($lockVersion);
            $this->states->assert('invoice', $invoice->status, 'posted', $invoice->label());

            $limit = $company->settings['approval']['invoice_above'] ?? null;
            if ($limit !== null && $userId !== null && $invoice->created_by === $userId) {
                $totalBase = Money::of($invoice->total, $invoice->currency)->convertTo($company->base_currency, (string) $invoice->fx_rate);
                if (Money::of((string) $limit, $company->base_currency)->minus($totalBase)->isNegative()) {
                    throw new LedgerException("{$invoice->label()} is above the approval limit; someone other than its creator must approve it.");
                }
            }

            // Gapless: the number is taken in this transaction, so a posting that fails takes none.
            if ($invoice->number === null) {
                if (! $this->numbers->isAutomatic($company->getKey(), $invoice->type)) {
                    throw new LedgerException('This company numbers invoices by hand: type the number from the invoice book first.');
                }
                $invoice->number = $this->numbers->take($company, $invoice->type, DateTimeImmutable::createFromInterface($invoice->issue_date));
            }
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

            return $invoice->fresh(['lines', 'einvoiceSubmissions']);
        });
    }

    /** The balanced journal draft for an invoice or credit note as its lines stand now (lines must be loaded). */
    public function journalDraftFor(Document $invoice): JournalDraft
    {
        $company = $this->current->get();

        // Each tax kind posts to its own liability account. VAT, GST and sales tax share one; a country
        // pack that has a turnover tax adds its own account.
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
