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
use Illuminate\Support\Str;

/**
 * Approve = post to the ledger + queue tax-authority clearance (invoices and credit notes), in one transaction.
 * Bills post the same way, to accounts payable, with no clearance: the supplier registers its own invoice.
 * State machine: draft or awaiting_approval -> posted.
 *
 * The one optional approval step: when the company sets settings.approval.invoice_above (bill_above for bills),
 * a document above that amount cannot be approved by the person who created it.
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
        if (! in_array($invoice->type, ['invoice', 'credit_note', 'bill', 'expense', 'vendor_credit'], true)) {
            throw new LedgerException("Document {$invoice->label()} is not an invoice, bill, expense or credit.");
        }

        $company = $this->current->get();

        return DB::transaction(function () use ($invoice, $company, $userId, $lockVersion) {
            // Lock the row: two people approving at once must not post twice.
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $invoice->assertVersion($lockVersion);
            $this->states->assert('invoice', $invoice->status, 'posted', $invoice->label());

            $limit = $company->settings['approval'][in_array($invoice->type, ['bill', 'vendor_credit'], true) ? 'bill_above' : 'invoice_above'] ?? null;
            if ($limit !== null && $userId !== null && $invoice->created_by === $userId) {
                $totalBase = Money::of($invoice->total, $invoice->currency)->convertTo($company->base_currency, (string) $invoice->fx_rate);
                if (Money::of((string) $limit, $company->base_currency)->minus($totalBase)->isNegative()) {
                    throw new LedgerException("{$invoice->label()} is above the approval limit; someone other than its creator must approve it.");
                }
            }

            $original = in_array($invoice->type, ['credit_note', 'vendor_credit'], true) ? Document::query()->lockForUpdate()->findOrFail($invoice->original_document_id) : null;
            if ($original !== null) {
                if (! in_array($original->status, ['posted', 'part_paid', 'paid'], true)) {
                    throw new LedgerException("{$original->label()} is not posted, so it cannot be credited.");
                }
                app(SaveInvoiceDraft::class)->assertCreditable($original, (string) $invoice->total, $invoice);
            }

            // Gapless: the number is taken in this transaction, so a posting that fails takes none.
            if ($invoice->number === null) {
                if (! $this->numbers->isAutomatic($company->getKey(), $invoice->type)) {
                    throw new LedgerException("This company numbers {$invoice->type}s by hand: type the number first.");
                }
                $invoice->number = $this->numbers->take($company, $invoice->type, DateTimeImmutable::createFromInterface($invoice->issue_date));
            }
            $entry = $this->posting->post($this->journalDraftFor($invoice->load('lines')), $userId);

            $invoice->status = 'posted';
            $invoice->journal_entry_id = $entry->getKey();
            if ($invoice->type === 'expense') {
                $invoice->amount_due = '0.00'; // paid on the spot (021)
            }
            $invoice->save();

            // A credit settles what its invoice (or bill) still owes; the rest stays as credit to apply later.
            if ($original !== null && in_array($original->status, ['posted', 'part_paid'], true)) {
                $apply = Money::of((string) $invoice->total, $invoice->currency);
                $due = Money::of((string) $original->amount_due, $invoice->currency);
                $apply = $due->minus($apply)->isNegative() ? $due : $apply;
                if (! $apply->isZero()) {
                    DB::table('credit_applications')->insert([
                        'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'credit_note_id' => $invoice->getKey(),
                        'invoice_id' => $original->getKey(), 'amount' => $apply->toDecimalString(), 'created_by' => $userId,
                    ]);
                }
            }

            if (in_array($invoice->type, ['invoice', 'credit_note'], true) && ($connector = $this->connectors->forCountry($company->country_code))) {
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

    /** The balanced journal draft for an invoice, credit note or bill as its lines stand now (lines must be loaded). */
    public function journalDraftFor(Document $invoice): JournalDraft
    {
        $company = $this->current->get();
        if (in_array($invoice->type, ['bill', 'expense', 'vendor_credit'], true)) {
            return $this->billDraft($invoice);
        }

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

    /**
     * A bill: each line's cost to its expense or asset account, input VAT to VAT receivable, the total to accounts
     * payable (an expense: to the account it was paid from). Input VAT is reclaimable only for a VAT-registered company; otherwise, and for any other kind of tax,
     * the tax is part of the cost.
     */
    private function billDraft(Document $bill): JournalDraft
    {
        $company = $this->current->get();
        $kinds = DB::table('tax_rates')->whereIn('id', $bill->lines->pluck('tax_rate_id')->filter())->pluck('kind', 'id');
        $vatReceivable = null;

        $lines = [];
        foreach ($bill->lines as $line) {
            $subtotal = Money::of($line->line_subtotal, $bill->currency);
            $tax = Money::of($line->line_tax, $bill->currency);
            $reclaimable = ! $tax->isZero() && $company->vat_registered && ($kinds[$line->tax_rate_id] ?? null) === 'vat';
            if ($reclaimable) {
                $vatReceivable ??= Account::system('vat_receivable')->getKey();
            }
            $lines[] = [
                'income_account_id' => $line->account_id,
                'subtotal' => $reclaimable ? $subtotal : $subtotal->plus($tax),
                'tax' => $reclaimable ? $tax : Money::zero($bill->currency),
                'tax_account_id' => $reclaimable ? $vatReceivable : null,
            ];
        }

        return $this->builder->build(
            companyId: $company->getKey(),
            documentId: $bill->getKey(),
            documentNumber: $bill->number,
            issueDate: DateTimeImmutable::createFromInterface($bill->issue_date),
            baseCurrency: $company->base_currency,
            currency: $bill->currency,
            fxRate: (string) $bill->fx_rate,
            customerId: $bill->contact_id,
            receivableAccountId: $bill->type === 'expense'
                ? Account::query()->findOrFail($bill->paid_from_account_id)->getKey()   // company-scoped: one of its own accounts
                : Account::system('accounts_payable')->getKey(),
            lines: $lines,
            sourceType: $bill->type,
        );
    }
}
