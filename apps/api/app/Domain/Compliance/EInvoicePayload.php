<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;

/**
 * Evenbooks' authority-neutral view of an invoice. Each connector maps this to its own schema.
 */
final readonly class EInvoicePayload
{
    /** @param list<array{line_no: int, description: string, quantity: string, unit_price: string, subtotal: string, tax: string, tax_code: ?string, authority_item_code: ?string}> $lines */
    public function __construct(
        public string $documentId,
        public string $documentType,
        public string $number,
        public string $issueDate,
        public string $currency,
        public string $sellerName,
        public ?string $sellerTaxId,
        public string $buyerName,
        public ?string $buyerTaxId,
        public string $subtotal,
        public string $taxTotal,
        public string $total,
        public array $lines,
    ) {
    }

    public static function fromDocument(Company $company, Document $document): self
    {
        $document->loadMissing(['lines', 'contact']);
        $taxCodes = \Illuminate\Support\Facades\DB::table('tax_rates')
            ->whereIn('id', $document->lines->pluck('tax_rate_id')->filter())
            ->pluck('code', 'id');
        $itemCodes = \Illuminate\Support\Facades\DB::table('items')
            ->whereIn('id', $document->lines->pluck('item_id')->filter())
            ->pluck('authority_item_code', 'id');

        return new self(
            documentId: $document->getKey(),
            documentType: $document->type,
            number: $document->number,
            issueDate: $document->issue_date->format('Y-m-d'),
            currency: $document->currency,
            sellerName: $company->legal_name,
            sellerTaxId: $company->tin,
            buyerName: $document->contact->name,
            buyerTaxId: $document->contact->tax_id,
            subtotal: (string) $document->subtotal,
            taxTotal: (string) $document->tax_total,
            total: (string) $document->total,
            lines: $document->lines->map(fn ($l) => [
                'line_no' => $l->line_no,
                'description' => $l->description,
                'quantity' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price,
                'subtotal' => (string) $l->line_subtotal,
                'tax' => (string) $l->line_tax,
                'tax_code' => $l->tax_rate_id ? ($taxCodes[$l->tax_rate_id] ?? null) : null,
                'authority_item_code' => $l->item_id ? ($itemCodes[$l->item_id] ?? null) : null,
            ])->all(),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
