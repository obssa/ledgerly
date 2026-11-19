<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: batch invoices. One invoice's lines, notes and currency are invoiced to several customers at once: one
 * ordinary invoice each, dated the same day and due by each customer's own terms, left as drafts or posted. All of them
 * are made or none: a customer whose invoice does not check out stops the batch, named in the message.
 */
final class BatchInvoices
{
    public const MAX = 100;

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly SaveInvoiceDraft $drafts,
        private readonly ApproveInvoice $approve,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<string> $contactIds
     * @return list<array{id: string, number: ?string, status: string, contact_id: string, contact: string, total: string}>
     */
    public function make(Document $source, array $contactIds, string $issueDate, bool $post, string $userId): array
    {
        if ($source->type !== 'invoice' || $source->status === 'void') {
            throw ValidationException::withMessages(['invoice' => ['A batch is made from an invoice that is not void.']]);
        }
        // Company-scoped: another company's contact is simply not found.
        $customers = Contact::query()->whereIn('id', $contactIds)->where(['is_customer' => true, 'is_active' => true])->orderBy('name')->get();
        if ($customers->count() !== count($contactIds)) {
            throw ValidationException::withMessages(['contact_ids' => ['Choose active customers of this company.']]);
        }
        $lines = $source->lines()->orderBy('line_no')->get()->map(fn ($l) => array_filter([
            'description' => (string) $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
            'discount_rate' => (string) $l->discount_rate, 'account_id' => (string) $l->account_id, 'tax_rate_id' => $l->tax_rate_id,
            'item_id' => $l->item_id, 'project_id' => $l->project_id, 'unit_id' => $l->unit_id,
        ], fn ($v) => $v !== null))->values()->all();

        return DB::transaction(function () use ($source, $customers, $lines, $issueDate, $post, $userId) {
            $made = [];
            foreach ($customers as $customer) {
                $terms = (int) ($customer->payment_terms_days ?? 0);
                try {
                    $draft = $this->drafts->handle([
                        'contact_id' => $customer->getKey(), 'issue_date' => $issueDate,
                        'due_date' => (new DateTimeImmutable($issueDate))->modify("+{$terms} days")->format('Y-m-d'),
                        'currency' => $source->currency, 'prices_include_tax' => (bool) $source->prices_include_tax, 'warehouse_id' => $source->warehouse_id,
                        'notes' => $source->notes, 'lines' => $lines,
                    ], null, $userId);
                    $invoice = $post ? $this->approve->handle($draft->refresh(), $userId) : $draft->refresh();
                } catch (ValidationException $e) {
                    $why = collect($e->errors())->flatten()->first() ?? 'the invoice does not check out';
                    throw ValidationException::withMessages(['contact_ids' => ["{$customer->name}: {$why} Nothing was made."]]);
                } catch (\App\Domain\Ledger\Exceptions\LedgerException $e) {
                    throw ValidationException::withMessages(['contact_ids' => ["{$customer->name}: {$e->getMessage()} Nothing was made."]]);
                }
                $made[] = ['id' => (string) $invoice->getKey(), 'number' => $invoice->number, 'status' => (string) $invoice->status,
                    'contact_id' => (string) $customer->getKey(), 'contact' => (string) $customer->name, 'total' => (string) $invoice->total];
            }
            $this->audit->record($this->current->id(), 'batch_invoiced', 'invoice', $source->getKey(), null,
                ['date' => $issueDate, 'posted' => $post, 'invoices' => array_map(fn ($m) => ['id' => $m['id'], 'number' => $m['number'], 'contact' => $m['contact']], $made)],
                userId: $userId);

            return $made;
        });
    }
}
