<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Module 2: saves a quote or a sales order (056). Lines and totals are calculated on the server exactly as an
 * invoice's (SaveInvoiceDraft::calculate). Neither posts anything and neither is owed: amount_due stays 0.
 *
 * A quote can be revised while it waits for the customer's answer, a sales order after it is confirmed: the
 * version the customer saw is kept first in document_versions, with the reason, and the number stays.
 */
final class SaveQuote
{
    /** The states each type can be edited in; after the first, an edit keeps the earlier version. */
    public const EDITABLE = ['quote' => ['draft', 'sent'], 'sales_order' => ['draft', 'accepted']];

    public function __construct(
        private readonly CurrentCompany $company,
        private readonly SaveInvoiceDraft $drafts,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $data validated by SaveInvoiceRequest, plus an optional reason for a revision */
    public function handle(array $data, string $type, ?Document $document, string $userId, ?Document $source = null): Document
    {
        return DB::transaction(function () use ($data, $type, $document, $userId, $source) {
            if ($document !== null) {
                $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $document->assertVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                $type = (string) $document->type;
                if (! in_array($document->status, self::EDITABLE[$type], true)) {
                    throw new LedgerException(self::noun($type) . ' ' . ($document->number ?? '') . " is {$document->status}; it can no longer be changed.");
                }
                // 067: the lines its invoices deliver stay as they were ordered.
                if ($type === 'sales_order' && Document::query()->where('source_document_id', $document->getKey())->exists()) {
                    throw new LedgerException("Sales order {$document->number} has invoices made from it; it can no longer be changed. Close it and make a new order for the rest.");
                }
            }
            // A quote's or an order's own lines deliver nothing.
            $data['lines'] = array_map(fn (array $l) => array_diff_key($l, ['source_line_id' => true]), $data['lines']);

            $data['salesperson_id'] = $this->drafts->salesperson($data, $document);
            $calc = $this->drafts->calculate($data);
            // Once it has gone to the customer (or been confirmed) its number stays; a draft takes a typed number in manual mode.
            $calc['header']['number'] = $document !== null && $document->status !== 'draft'
                ? $document->number
                : $this->drafts->draftNumber($data, $document, $type);
            unset($calc['header']['paid_from_account_id'], $calc['header']['payment_method'], $calc['header']['reference']);

            $revised = $document !== null && $document->status !== 'draft';
            if ($revised) {
                DocumentVersion::query()->create([
                    'document_id' => $document->getKey(),
                    'version' => (int) DocumentVersion::query()->where('document_id', $document->getKey())->max('version') + 1,
                    'snapshot' => $document->load('lines')->toArray(),   // what the customer saw
                    'reason' => isset($data['reason']) && mb_strlen(trim((string) $data['reason'])) >= 3 ? trim((string) $data['reason']) : 'Revised',
                    'edited_by' => $userId,
                ]);
            }

            $before = $document?->only(['number', 'status', 'total']);
            $document ??= new Document(['type' => $type, 'created_by' => $userId]);
            if ($source !== null) {
                $document->forceFill(['source_document_id' => $source->getKey()]);
            }
            $document->fill($calc['header']);
            $document->forceFill($calc['totals']);
            $document->amount_due = '0.00';   // nothing is owed on a quote or an order (056)
            $document->save();

            $document->lines()->delete();
            $document->lines()->createMany($calc['lines']);
            $document->refresh()->load('lines');

            $this->audit->record($this->company->id(), $revised ? "{$type}_revised" : "{$type}_saved", $type, $document->getKey(),
                $before, $document->only(['number', 'status', 'total', 'source_document_id']), userId: $userId);

            return $document;
        });
    }

    public static function noun(string $type): string
    {
        return $type === 'sales_order' ? 'Sales order' : 'Quote';
    }
}
