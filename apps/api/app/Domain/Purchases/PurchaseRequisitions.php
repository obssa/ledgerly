<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Sales\Actions\SaveQuote;
use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 3: purchase requisitions (084). A member asks for something to be bought; someone who approves bills approves
 * or rejects it, never the person who asked; someone who enters bills turns an approved one into a draft purchase order
 * to the supplier they choose, at the prices they give (the estimate by default). Every step is audited; nothing posts.
 */
final class PurchaseRequisitions
{
    public function __construct(private readonly CurrentCompany $current, private readonly SaveQuote $orders, private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array{reason: string, needed_by?: ?string, lines: list<array{description: string, quantity: string, estimated_price?: ?string, item_id?: ?string}>} $data
     * @return array<string, mixed>
     */
    public function request(array $data, string $userId): array
    {
        $items = array_filter(array_column($data['lines'], 'item_id'));
        // Company-scoped: another company's item is simply not found.
        if ($items !== [] && Item::query()->whereIn('id', $items)->count() !== count(array_unique($items))) {
            throw ValidationException::withMessages(['lines' => ['Ask for the company\'s own items.']]);
        }
        $id = (string) Str::uuid7();
        $lines = array_map(fn (array $l) => array_filter([
            'description' => trim($l['description']), 'quantity' => (string) $l['quantity'],
            'estimated_price' => isset($l['estimated_price']) ? (string) $l['estimated_price'] : null, 'item_id' => $l['item_id'] ?? null,
        ], fn ($v) => $v !== null), $data['lines']);
        DB::table('purchase_requisitions')->insert(['id' => $id, 'company_id' => $this->current->id(), 'requested_by' => $userId,
            'reason' => trim($data['reason']), 'needed_by' => $data['needed_by'] ?? null, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR)]);
        $this->audit->record($this->current->id(), 'requisition_requested', 'purchase_requisition', $id, null,
            ['reason' => trim($data['reason']), 'lines' => count($lines)], userId: $userId);

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function decide(string $id, bool $approve, ?string $note, string $userId): array
    {
        return DB::transaction(function () use ($id, $approve, $note, $userId) {
            $r = $this->lock($id);
            if ($r->status !== 'requested') {
                throw ValidationException::withMessages(['status' => ["This requisition is {$r->status}: it is no longer waiting for a decision."]]);
            }
            if ($r->requested_by === $userId) {
                throw ValidationException::withMessages(['status' => ['You asked for this: someone else approves or rejects it.']]);
            }
            $status = $approve ? 'approved' : 'rejected';
            DB::table('purchase_requisitions')->where('id', $id)->update(['status' => $status, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->record($this->current->id(), "requisition_{$status}", 'purchase_requisition', $id, ['status' => 'requested'], ['status' => $status], $note, $userId);

            return $this->find($id);
        });
    }

    /** @return array<string, mixed> */
    public function withdraw(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $r = $this->lock($id);
            if ($r->requested_by !== $userId || $r->status !== 'requested') {
                throw ValidationException::withMessages(['status' => ['Only a requisition of yours still waiting for a decision is withdrawn.']]);
            }
            DB::table('purchase_requisitions')->where('id', $id)->update(['status' => 'withdrawn']);
            $this->audit->record($this->current->id(), 'requisition_withdrawn', 'purchase_requisition', $id, ['status' => 'requested'], ['status' => 'withdrawn'], userId: $userId);

            return $this->find($id);
        });
    }

    /**
     * Turns an approved requisition into a draft purchase order.
     *
     * @param array{contact_id: string, account_id: string, warehouse_id?: ?string, prices?: array<int, ?string>} $data
     * @return array<string, mixed>
     */
    public function order(string $id, array $data, string $userId): array
    {
        $supplier = Contact::query()->where(['is_vendor' => true, 'is_active' => true])->find($data['contact_id'])
            ?? throw ValidationException::withMessages(['contact_id' => ['Choose an active supplier of this company.']]);

        return DB::transaction(function () use ($id, $data, $supplier, $userId) {
            $r = $this->lock($id);
            if ($r->status !== 'approved') {
                throw ValidationException::withMessages(['status' => ["This requisition is {$r->status}: only an approved one is ordered."]]);
            }
            $lines = json_decode((string) $r->lines, true, 512, JSON_THROW_ON_ERROR);
            $order = $this->orders->handle([
                'contact_id' => $supplier->getKey(), 'issue_date' => $this->current->today(), 'currency' => $this->current->get()->base_currency,
                'warehouse_id' => $data['warehouse_id'] ?? null, 'notes' => mb_substr("From requisition: {$r->reason}", 0, 2000),
                'lines' => array_map(fn (array $l, int $i) => array_filter([
                    'description' => $l['description'], 'quantity' => $l['quantity'],
                    'unit_price' => (string) ($data['prices'][$i] ?? $l['estimated_price'] ?? '0'),
                    'account_id' => $data['account_id'], 'item_id' => $l['item_id'] ?? null,
                ], fn ($v) => $v !== null), $lines, array_keys($lines)),
            ], 'purchase_order', null, $userId);
            DB::table('purchase_requisitions')->where('id', $id)->update(['status' => 'ordered', 'purchase_order_id' => $order->getKey()]);
            $this->audit->record($this->current->id(), 'requisition_ordered', 'purchase_requisition', $id, ['status' => 'approved'],
                ['status' => 'ordered', 'purchase_order_id' => $order->getKey(), 'supplier' => $supplier->name], userId: $userId);

            return $this->find($id);
        });
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        $r = DB::table('purchase_requisitions as r')->join('users as u', 'u.id', '=', 'r.requested_by')->leftJoin('users as d', 'd.id', '=', 'r.decided_by')
            ->leftJoin('documents as o', 'o.id', '=', 'r.purchase_order_id')->where('r.id', $id)
            ->first(['r.*', 'u.name as requested_by_name', 'd.name as decided_by_name', 'o.number as purchase_order_number']) ?? abort(404);

        return [...(array) $r, 'lines' => json_decode((string) $r->lines, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function lock(string $id): object
    {
        return DB::table('purchase_requisitions')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
    }
}
