<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\DocumentNumbers;
use App\Domain\Sales\InvoicePostingBuilder;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 1: an invoice a customer still owed, or a bill the company still owed, on the conversion date, brought in
 * from the old books (057). It keeps its own number and dates for ageing, statements and payments, owes its open
 * amount, and posts on the conversion date through the posting service: an invoice Dr accounts receivable, Cr opening
 * balance equity; a bill Dr opening balance equity, Cr accounts payable. No income, cost or tax (that was in the old
 * books), and it is never sent to the tax authority. A mistake is voided and entered again.
 */
final class EnterOpeningDocument
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly InvoicePostingBuilder $builder,
        private readonly PostingService $posting,
        private readonly ExchangeRates $rates,
        private readonly DocumentNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param 'invoice'|'bill' $type
     * @param array{contact_id: string, number?: ?string, reference?: ?string, issue_date: string, due_date?: ?string, currency: string, fx_rate?: ?string, amount: string} $data
     */
    public function handle(string $type, array $data, string $userId): Document
    {
        $company = $this->current->get();
        $conversion = $company->conversion_date?->format('Y-m-d');
        if ($conversion === null) {
            throw ValidationException::withMessages(['conversion_date' => ['Set the conversion date under Company settings first: open invoices and bills are as at that date.']]);
        }
        if ($data['issue_date'] > $conversion) {
            throw ValidationException::withMessages(['issue_date' => ["Dated on or before the conversion date ({$conversion}): later ones are entered as usual."]]);
        }
        $number = isset($data['number']) && trim((string) $data['number']) !== '' ? trim((string) $data['number']) : null;
        if ($type === 'invoice' && $number === null) {
            throw ValidationException::withMessages(['number' => ['The invoice number from the old books.']]);
        }
        if ($type === 'bill' && $number === null && ! $this->numbers->isAutomatic($company->getKey(), 'bill')) {
            throw ValidationException::withMessages(['number' => ['Type our number for the bill: numbering is manual.']]);
        }
        // An old number in the company's own automatic series would one day collide with a number Ledgerly gives.
        $own = $this->numbers->settings($company->getKey(), $type);
        if ($number !== null && $own['mode'] === 'automatic' && $own['prefix'] !== '' && str_starts_with($number, $own['prefix'])) {
            throw ValidationException::withMessages(['number' => ["Numbers starting with {$own['prefix']} are Ledgerly's own: keep the old number with another prefix, such as OLD-{$number}."]]);
        }
        if ($number !== null && Document::query()->where(['type' => $type, 'number' => $number])->exists()) {
            throw ValidationException::withMessages(['number' => ["{$number} is already used by another {$type}."]]);
        }

        return DB::transaction(function () use ($type, $data, $userId, $company, $conversion, $number) {
            $equity = Account::system('opening_balance_equity')->getKey();
            $currency = (string) $data['currency'];
            $amount = Money::of((string) $data['amount'], $currency)->toDecimalString();
            $fx = $this->rates->forDocument($company, $currency, $conversion, isset($data['fx_rate']) ? (string) $data['fx_rate'] : null);
            $number ??= $this->numbers->take($company, 'bill', new DateTimeImmutable($conversion));

            $document = new Document([
                'type' => $type, 'number' => $number, 'contact_id' => $data['contact_id'], 'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null, 'currency' => $currency, 'fx_rate' => $fx, 'created_by' => $userId,
                'reference' => $type === 'bill' ? (trim((string) ($data['reference'] ?? '')) ?: null) : null,
                'notes' => 'Brought in from the old books at conversion',
            ]);
            $document->forceFill(['opening' => true, 'subtotal' => $amount, 'tax_total' => '0.00', 'total' => $amount, 'amount_due' => $amount]);
            $document->save();
            $document->lines()->create([
                'line_no' => 1, 'description' => 'Open balance brought in at conversion', 'quantity' => '1', 'unit_price' => $amount,
                'discount_rate' => '0', 'account_id' => $equity, 'line_subtotal' => $amount, 'line_tax' => '0.00',
            ]);

            $entry = $this->posting->post($this->builder->build(
                companyId: $company->getKey(),
                documentId: $document->getKey(),
                documentNumber: $number,
                issueDate: new DateTimeImmutable($conversion),   // the opening entry's date, whatever the document's own date
                baseCurrency: $company->base_currency,
                currency: $currency,
                fxRate: $fx,
                customerId: $data['contact_id'],
                receivableAccountId: Account::system($type === 'bill' ? 'accounts_payable' : 'accounts_receivable')->getKey(),
                lines: [['income_account_id' => $equity, 'subtotal' => Money::of($amount, $currency), 'tax' => Money::zero($currency), 'tax_account_id' => null]],
                sourceType: $type,
            ), $userId);

            $document->journal_entry_id = $entry->getKey();
            $document->status = 'posted';
            $document->save();
            $this->audit->record($company->getKey(), 'opening_document_entered', $type, $document->getKey(), null,
                ['number' => $number, 'reference' => $document->reference, 'issue_date' => $data['issue_date'], 'currency' => $currency, 'amount' => $amount,
                    'journal_entry' => $entry->number, 'as_at' => $conversion], userId: $userId);

            return $document->refresh()->load('lines');
        });
    }
}
