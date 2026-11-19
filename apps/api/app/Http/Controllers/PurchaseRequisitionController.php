<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Purchases\PurchaseRequisitions;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Module 3: purchase requisitions (084). Asking takes bills.request; the person who asked sees and withdraws their own.
 * Approving or rejecting takes bills.approve; ordering (a draft purchase order) takes bills.create. Someone with either
 * sees every requisition. Rows are read through row-level security; another company's requisition is not found.
 */
final class PurchaseRequisitionController extends Controller
{
    public function __construct(private readonly PurchaseRequisitions $requisitions, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.request');
        $all = $this->seesAll();
        $rows = DB::table('purchase_requisitions as r')->join('users as u', 'u.id', '=', 'r.requested_by')
            ->when(! $all, fn ($q) => $q->where('r.requested_by', $request->user()->getKey()))
            ->orderByRaw("CASE r.status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('r.created_at')->limit(500)
            ->get(['r.id', 'r.status', 'r.reason', 'r.needed_by', 'r.created_at', 'r.purchase_order_id', 'u.name as requested_by_name', 'r.requested_by', 'r.lines'])
            ->map(fn ($r) => [...(array) $r, 'lines' => count(json_decode((string) $r->lines, true) ?? [])]);
        $m = $this->current->membership();

        return response()->json(['data' => $rows, 'can' => ['see_all' => $all, 'approve' => (bool) $m?->can('bills.approve'), 'order' => (bool) $m?->can('bills.create')]]);
    }

    public function show(Request $request, string $purchaseRequisition): JsonResponse
    {
        $this->authorizeCompany('bills.request');

        return response()->json($this->present($request, $this->visible($request, $purchaseRequisition)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.request');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'needed_by' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . $this->current->today()],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.estimated_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.item_id' => ['nullable', 'uuid'],
        ], ['reason.required' => 'Say what it is for.']);

        return response()->json($this->present($request, $this->requisitions->request($data, $request->user()->getKey())), 201);
    }

    public function approve(Request $request, string $purchaseRequisition): JsonResponse
    {
        $this->authorizeCompany('bills.approve');
        $id = $this->id($purchaseRequisition);   // another company's requisition is not found before anything is checked
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json($this->present($request, $this->requisitions->decide($id, true, $note, $request->user()->getKey())));
    }

    public function reject(Request $request, string $purchaseRequisition): JsonResponse
    {
        $this->authorizeCompany('bills.approve');
        $id = $this->id($purchaseRequisition);
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']], ['note.required' => 'Say why, for the person who asked.'])['note'];

        return response()->json($this->present($request, $this->requisitions->decide($id, false, $note, $request->user()->getKey())));
    }

    public function withdraw(Request $request, string $purchaseRequisition): JsonResponse
    {
        $this->authorizeCompany('bills.request');
        $this->visible($request, $purchaseRequisition);

        return response()->json($this->present($request, $this->requisitions->withdraw($purchaseRequisition, $request->user()->getKey())));
    }

    public function order(Request $request, string $purchaseRequisition): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $id = $this->id($purchaseRequisition);
        $data = $request->validate([
            'contact_id' => ['required', 'uuid'],
            // What it is bought as: an expense account, a fixed asset or a prepayment of the company's own, in use.
            'account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('is_active', true)
                ->where(fn (Builder $w) => $w->where('type', 'expense')->orWhere(fn (Builder $a) => $a->where('type', 'asset')->whereIn('subtype', ['fixed_asset', 'prepayment', 'inventory']))))],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'prices' => ['nullable', 'array', 'max:100'],
            'prices.*' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);

        return response()->json($this->present($request, $this->requisitions->order($id, $data, $request->user()->getKey())));
    }

    private function seesAll(): bool
    {
        $m = $this->current->membership();

        return (bool) ($m?->can('bills.approve') || $m?->can('bills.create'));
    }

    private function id(string $id): string
    {
        return Str::isUuid($id) && DB::table('purchase_requisitions')->where('id', $id)->exists() ? $id : abort(404);
    }

    /** @return array<string, mixed> one the person asking may see: their own, or any for those who decide or order */
    private function visible(Request $request, string $id): array
    {
        $r = $this->requisitions->find($this->id($id));
        if (! $this->seesAll() && $r['requested_by'] !== $request->user()->getKey()) {
            abort(404);
        }

        return $r;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function present(Request $request, array $r): array
    {
        $own = $r['requested_by'] === $request->user()->getKey();
        $m = $this->current->membership();

        $order = $r['status'] === 'approved' && (bool) $m?->can('bills.create');

        return [...$r, 'can' => [
            'withdraw' => $own && $r['status'] === 'requested',
            'decide' => ! $own && $r['status'] === 'requested' && (bool) $m?->can('bills.approve'),
            'order' => $order,
        ], 'options' => $order ? [
            // For the order form: the company's active suppliers and what it may be bought as.
            'suppliers' => \App\Domain\Sales\Models\Contact::query()->where(['is_vendor' => true, 'is_active' => true])->whereNull('employee_user_id')
                ->orderBy('name')->limit(500)->get(['id', 'name']),
            'accounts' => \App\Domain\Ledger\Models\Account::query()->where('is_active', true)->where(fn ($w) => $w->where('type', 'expense')
                ->orWhere(fn ($a) => $a->where('type', 'asset')->whereIn('subtype', ['fixed_asset', 'prepayment', 'inventory'])))->orderBy('code')->get(['id', 'code', 'name']),
        ] : null];
    }
}
