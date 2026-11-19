<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\LandedCosts;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 4: landed costs (060). On a bill for freight, duty or clearing: what it was spread onto, what is left, and the
 * posted bills whose goods it can be added to. Applying one posts an entry and raises the value of the goods still on
 * hand (inventory.adjust: it changes stock values). Bills are loaded through the company-scoped model.
 */
final class LandedCostController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly LandedCosts $landed)
    {
    }

    public function index(Document $bill): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $rows = fn (string $column) => DB::table('landed_costs as l')
            ->join('documents as c', 'c.id', '=', 'l.cost_bill_id')->join('documents as g', 'g.id', '=', 'l.goods_bill_id')
            ->where('l.company_id', $bill->company_id)->where("l.{$column}", $bill->getKey())
            ->orderBy('l.created_at')
            ->get(['l.id', 'l.amount', 'l.method', 'l.applied_on', 'l.journal_entry_id', 'c.id as cost_bill_id', 'c.number as cost_bill_number',
                'g.id as goods_bill_id', 'g.number as goods_bill_number']);
        $posted = $bill->type === 'bill' && in_array($bill->status, ['posted', 'part_paid', 'paid'], true);
        $brought = DB::table('stock_moves')->where(['company_id' => $bill->company_id, 'kind' => 'purchase'])->whereNotNull('document_id')->distinct()->pluck('document_id')
            // A bill that billed goods received on notes (069) brought them in too.
            ->concat(DB::table('bill_receipt_matches')->where('company_id', $bill->company_id)->distinct()->pluck('bill_id'))->unique()->values();
        $isGoods = $brought->contains($bill->getKey());

        return response()->json([
            'spread' => $rows('cost_bill_id'),           // this bill's cost, added onto others' goods
            'received' => $rows('goods_bill_id'),        // costs added onto this bill's goods
            'left' => $posted && ! $isGoods ? $this->landed->left($bill)->toDecimalString() : '0.00',
            // Posted bills that brought stock in, newest first: where this bill's cost can go.
            'candidates' => $posted && ! $isGoods ? Document::query()->where('type', 'bill')->whereIn('status', ['posted', 'part_paid', 'paid'])
                ->whereIn('id', $brought)->whereKeyNot($bill->getKey())->with('contact:id,name')->orderByDesc('issue_date')->limit(100)
                ->get(['id', 'number', 'reference', 'contact_id', 'issue_date', 'total', 'currency'])
                ->map(fn (Document $d) => [...$d->only(['id', 'number', 'reference', 'issue_date', 'total', 'currency']), 'supplier' => $d->contact?->name])->values() : [],
            'can' => ['apply' => (bool) $this->current->membership()?->can('inventory.adjust')],
        ]);
    }

    public function store(Request $request, Document $bill): JsonResponse
    {
        $this->authorizeCompany('inventory.adjust');
        $data = $request->validate([
            'goods_bill_id' => ['required', 'uuid'],
            'method' => ['required', 'in:value,quantity'],
            'amount' => ['nullable', 'decimal:0,2', 'gt:0'],   // none: all that is left of the cost bill
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // Through the company-scoped model: another company's bill is simply not found.
        $goods = Document::query()->where('type', 'bill')->find($data['goods_bill_id']);
        if ($goods === null) {
            abort(404);
        }

        return response()->json($this->landed->apply($bill, $goods, $data['method'], $data['amount'] ?? null, $data['date'] ?? $this->current->today(),
            $request->user()->getKey()), 201);
    }
}
