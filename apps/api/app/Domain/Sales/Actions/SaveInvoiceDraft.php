<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Money;
use App\Domain\Sales\Models\Document;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Creates or replaces a draft invoice: recalculates every line with the TaxCalculator on the server,
 * so totals never depend on what the browser sent.
 */
final class SaveInvoiceDraft
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxCalculator $tax,
    ) {
    }

    /**
     * @param array{contact_id: string, number: string, issue_date: string, due_date?: ?string, currency: string,
     *              fx_rate?: ?string, prices_include_tax?: bool, language?: string, notes?: ?string,
     *              lines: list<array{description: string, quantity: string, unit_price: string, discount_rate?: string,
     *                                account_id: string, tax_rate_id?: ?string, item_id?: ?string}>} $data
     */
    public function handle(array $data, ?Document $document = null, ?string $userId = null): Document
    {
        if ($document && ! $document->isEditable()) {
            throw new LedgerException('Posted invoices cannot be edited; issue a credit note instead.');
        }

        return DB::transaction(function () use ($data, $document, $userId) {
            $currency = $data['currency'];
            $inclusive = (bool) ($data['prices_include_tax'] ?? false);
            $rates = DB::table('tax_rates')
                ->whereIn('id', array_filter(array_column($data['lines'], 'tax_rate_id')))
                ->pluck('rate', 'id');

            $document ??= new Document(['type' => 'invoice', 'created_by' => $userId]);
            $document->fill([
                'contact_id' => $data['contact_id'],
                'number' => $data['number'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $currency,
                'fx_rate' => $data['fx_rate'] ?? '1',
                'prices_include_tax' => $inclusive,
                'language' => $data['language'] ?? 'en',
                'notes' => $data['notes'] ?? null,
            ]);
            $document->status = 'draft';

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
            $document->subtotal = $totals->subtotal->toDecimalString();
            $document->tax_total = $totals->tax->toDecimalString();
            $document->total = $totals->total->toDecimalString();
            $document->amount_due = $totals->total->toDecimalString();
            $document->save();

            $document->lines()->delete();
            $document->lines()->createMany($lineRows);

            return $document->load('lines');
        });
    }
}
