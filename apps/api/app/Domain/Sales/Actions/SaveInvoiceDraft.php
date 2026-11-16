<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Ledger\ExchangeRates;
use App\Domain\Sales\DocumentNumbers;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Money;
use App\Domain\Sales\Models\Document;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or replaces a draft invoice, credit note or bill: recalculates every line with the TaxCalculator on the
 * server, so totals never depend on what the browser sent.
 */
final class SaveInvoiceDraft
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxCalculator $tax,
        private readonly ExchangeRates $rates,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /**
     * @param array{contact_id: string, number: string, issue_date: string, due_date?: ?string, currency: string,
     *              fx_rate?: ?string, prices_include_tax?: bool, language?: string, notes?: ?string,
     *              lines: list<array{description: string, quantity: string, unit_price: string, discount_rate?: string,
     *                                account_id: string, tax_rate_id?: ?string, item_id?: ?string}>} $data
     */
    public function handle(array $data, ?Document $document = null, ?string $userId = null, ?Document $original = null, string $newType = 'invoice'): Document
    {
        if ($document && ! $document->isEditable()) {
            throw new LedgerException('Posted invoices are edited through EditPostedInvoice, which keeps their history.');
        }

        return DB::transaction(function () use ($data, $document, $userId, $original, $newType) {
            // A credit note corrects one invoice: its customer, currency and rate are the invoice's.
            if ($original === null && $document?->type === 'credit_note') {
                $original = Document::query()->findOrFail($document->original_document_id);
            }
            $type = $original !== null ? 'credit_note' : ($document->type ?? $newType);
            if ($original !== null) {
                $data['contact_id'] = $original->contact_id;
                $data['currency'] = $original->currency;
                $data['fx_rate'] = (string) $original->fx_rate;
            }

            $calc = $this->calculate($data);
            $calc['header']['number'] = $this->draftNumber($data, $document, $type);
            if ($original !== null) {
                $this->assertCreditable($original, $calc['totals']['total'], $document);
            }

            if ($document) {
                $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $document->assertVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if (! $document->isEditable()) {
                    throw new LedgerException('Posted invoices are edited through EditPostedInvoice, which keeps their history.');
                }
            }

            $document ??= new Document(['type' => $type, 'created_by' => $userId, 'original_document_id' => $original?->getKey()]);
            $document->fill($calc['header']);
            $document->status = 'draft'; // a change while awaiting approval sends it back to draft
            $document->forceFill($calc['totals']);
            $document->amount_due = $calc['totals']['total'];
            $document->save();

            $document->lines()->delete();
            $document->lines()->createMany($calc['lines']);

            return $document->refresh()->load('lines'); // refresh: the database bumped lock_version
        });
    }

    /**
     * Automatic numbering: a draft has no number; it gets one when posted. Manual numbering (pre-printed books):
     * the number typed on the draft, unique per company and type (database constraint).
     */
    private function draftNumber(array $data, ?Document $document, string $type = 'invoice'): ?string
    {
        $number = isset($data['number']) && trim((string) $data['number']) !== '' ? trim((string) $data['number']) : null;
        $automatic = $this->numbers->isAutomatic($this->company->id(), $type);

        $noun = in_array($type, ['bill', 'expense'], true) ? $type : 'invoice';
        if ($automatic && $number !== null) {
            throw ValidationException::withMessages(['number' => [ucfirst($noun) . 's are numbered when they are posted. Leave the number empty.']]);
        }
        if (! $automatic && $number === null) {
            throw ValidationException::withMessages(['number' => ["Type the {$noun} number."]]);
        }
        if ($number !== null && Document::query()->where(['type' => $type, 'number' => $number])
            ->when($document, fn ($q) => $q->whereKeyNot($document->getKey()))->exists()) {
            throw ValidationException::withMessages(['number' => ["{$number} is already used by another {$noun}."]]);
        }

        return $number;
    }

    /**
     * The credit notes of one invoice never add up to more than the invoice (also a database rule, 017).
     */
    public function assertCreditable(Document $original, string $total, ?Document $except = null): void
    {
        $credited = (string) (Document::query()->where(['type' => 'credit_note', 'original_document_id' => $original->getKey()])
            ->whereIn('status', ['posted', 'part_paid', 'paid'])
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('total'));
        $left = Money::of((string) $original->total, $original->currency)->minus(Money::of($credited, $original->currency));
        if ($left->minus(Money::of($total, $original->currency))->isNegative()) {
            throw ValidationException::withMessages(['lines' => ["{$original->number} can be credited by {$left->toDecimalString()} at most."]]);
        }
    }

    /**
     * Server-side recalculation of an invoice from the submitted data. Shared by draft saving and by
     * EditPostedInvoice, so a draft and an edited posted invoice always compute the same way.
     *
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{subtotal: string, tax_total: string, total: string}}
     */
    public function calculate(array $data): array
    {
        $currency = $data['currency'];
        $inclusive = (bool) ($data['prices_include_tax'] ?? false);
        $rates = DB::table('tax_rates')
            ->whereIn('id', array_filter(array_column($data['lines'], 'tax_rate_id')))
            ->pluck('rate', 'id');

        $amounts = [];
        $lineRows = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $rate = isset($line['tax_rate_id']) ? (string) ($rates[$line['tax_rate_id']] ?? '0') : '0';
            $result = $this->tax->line(
                Money::of($line['unit_price'], $currency),
                (string) $line['quantity'],
                (string) ($line['discount_rate'] ?? '0'),
                $rate,
                $inclusive,
            );
            $amounts[] = $result;
            $lineRows[] = [
                'line_no' => $i + 1,
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_rate' => (string) ($line['discount_rate'] ?? '0'),
                'tax_rate_id' => $line['tax_rate_id'] ?? null,
                'account_id' => $line['account_id'],
                'line_subtotal' => $result->subtotal->toDecimalString(),
                'line_tax' => $result->tax->toDecimalString(),
            ];
        }

        $totals = $this->tax->document($amounts, $currency);

        return [
            'header' => [
                'contact_id' => $data['contact_id'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $currency,
                // 1 for the base currency; else the rate given, or the company's rate for the issue date.
                'fx_rate' => $this->rates->forDocument($this->company->get(), $currency, $data['issue_date'], isset($data['fx_rate']) ? (string) $data['fx_rate'] : null),
                'prices_include_tax' => $inclusive,
                'language' => $data['language'] ?? 'en',
                'notes' => $data['notes'] ?? null,
                'reference' => isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
                // An expense says where it was paid from and how (021); other documents leave these empty.
                'paid_from_account_id' => $data['paid_from_account_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
            ],
            'lines' => $lineRows,
            'totals' => [
                'subtotal' => $totals->subtotal->toDecimalString(),
                'tax_total' => $totals->tax->toDecimalString(),
                'total' => $totals->total->toDecimalString(),
            ],
        ];
    }
}
