<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 4: landed costs (060). A posted bill for freight, duty or clearing is added to the cost of the goods another
 * posted bill brought in. The amount is spread over the goods bill's stock, item by item and warehouse by warehouse, by
 * value or by quantity; the share of goods still on hand raises their value (a landed_cost move), the share of goods
 * already sold goes to cost of goods sold. One entry: Dr Inventory and Cost of goods sold, Cr the accounts the cost
 * bill was posted to, in proportion. Kept, never changed.
 */
final class LandedCosts
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly StockLedger $stock,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {
    }

    private static function positive(Money $m): bool
    {
        return ! $m->isZero() && ! $m->isNegative();
    }

    /** What is left of a cost bill to spread, in the base currency: its amount before tax, less what was spread. */
    /**
     * The goods a bill billed from goods received notes (069): for each match, the notes' moves of that order line's
     * item, oldest first, up to the quantity matched (in the item's own unit), each at its own value per unit.
     *
     * @return list<object{item_id: string, warehouse_id: string, quantity: string, amount: string}>
     */
    private function receivedOnNotes(Document $bill, \App\Domain\Tenancy\Models\Company $company): array
    {
        $matches = DB::table('bill_receipt_matches as m')->join('document_lines as bl', 'bl.id', '=', 'm.bill_line_id')
            ->join('document_lines as ol', 'ol.id', '=', 'm.order_line_id')->where(['m.company_id' => $company->getKey(), 'm.bill_id' => $bill->getKey()])
            ->get(['bl.item_id', 'm.order_line_id', 'm.quantity', 'ol.unit_factor']);
        $out = [];
        foreach ($matches as $m) {
            $need = DocumentStock::baseQuantity((object) ['quantity' => (string) $m->quantity, 'unit_factor' => $m->unit_factor]);
            $notes = DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
                ->where(['gl.order_line_id' => $m->order_line_id, 'g.status' => 'posted'])->distinct()->pluck('gl.goods_receipt_id');
            $moves = DB::table('stock_moves')->whereIn('goods_receipt_id', $notes)->where(['item_id' => $m->item_id, 'kind' => 'purchase'])
                ->where('quantity', '>', 0)->orderBy('moved_on')->orderBy('seq')->get(['warehouse_id', 'quantity', 'amount']);
            foreach ($moves as $mv) {
                if (bccomp($need, '0', 2) <= 0) {
                    break;
                }
                $take = bccomp((string) $mv->quantity, $need, 2) >= 0 ? $need : (string) $mv->quantity;
                $amount = bccomp($take, (string) $mv->quantity, 2) === 0 ? (string) $mv->amount
                    : bcdiv(bcmul((string) $mv->amount, $take, 8), (string) $mv->quantity, 2);
                $out[] = (object) ['item_id' => (string) $m->item_id, 'warehouse_id' => (string) $mv->warehouse_id, 'quantity' => $take, 'amount' => $amount];
                $need = bcsub($need, $take, 2);
            }
        }

        return $out;
    }

    public function left(Document $cost): Money
    {
        $base = $this->current->get()->base_currency;
        $total = Money::of(bcmul((string) $cost->subtotal, (string) $cost->fx_rate, 6), $base)->roundToCurrency();
        $spread = (string) DB::table('landed_costs')->where(['company_id' => $cost->company_id, 'cost_bill_id' => $cost->getKey()])->sum('amount');

        return $total->minus(Money::of($spread === '' ? '0' : $spread, $base));
    }

    /** @return array{id: string, amount: string, inventory: string, cost_of_sales: string, journal_entry_id: string} */
    public function apply(Document $cost, Document $goods, string $method, ?string $amount, string $date, string $userId): array
    {
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($cost, $goods, $method, $amount, $date, $userId, $company, $base) {
            $cost = Document::query()->lockForUpdate()->with('lines')->findOrFail($cost->getKey());
            $posted = fn (Document $d) => $d->type === 'bill' && in_array($d->status, ['posted', 'part_paid', 'paid'], true);
            if (! $posted($cost) || ! $posted($goods) || $cost->is($goods)) {
                throw ValidationException::withMessages(['goods_bill_id' => ['A landed cost joins two posted bills: the cost bill and the bill that brought the goods in.']]);
            }
            if (DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $cost->getKey(), 'kind' => 'purchase'])->exists()
                || DB::table('bill_receipt_matches')->where(['company_id' => $company->getKey(), 'bill_id' => $cost->getKey()])->exists()) {
                throw ValidationException::withMessages(['bill' => ["{$cost->label()} brought goods in itself: a landed cost is a bill for freight, duty or clearing."]]);
            }
            $received = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $goods->getKey(), 'kind' => 'purchase'])
                ->get(['item_id', 'warehouse_id', 'quantity', 'amount'])
                ->concat($this->receivedOnNotes($goods, $company));
            if ($received->isEmpty()) {
                throw ValidationException::withMessages(['goods_bill_id' => ["{$goods->label()} brought no stock in."]]);
            }
            $earliest = max($cost->issue_date->format('Y-m-d'), $goods->issue_date->format('Y-m-d'));
            if ($date < $earliest || $date > $this->current->today()) {
                throw ValidationException::withMessages(['date' => ["Dated from {$earliest} (both bills) to today."]]);
            }
            $left = $this->left($cost);
            $spread = $amount === null ? $left : Money::of($amount, $base);
            if (! self::positive($spread) || self::positive($spread->minus($left))) {
                throw ValidationException::withMessages(['amount' => ["Up to {$left->toDecimalString()} of {$cost->label()} is left to spread."]]);
            }

            // The goods by item and warehouse, weighed by value or quantity.
            $groups = [];
            foreach ($received as $m) {
                $key = $m->item_id . '|' . $m->warehouse_id;
                $groups[$key] ??= ['item_id' => $m->item_id, 'warehouse_id' => $m->warehouse_id, 'quantity' => '0', 'weight' => '0'];
                $groups[$key]['quantity'] = bcadd($groups[$key]['quantity'], (string) $m->quantity, 2);
                $groups[$key]['weight'] = bcadd($groups[$key]['weight'], $method === 'quantity' ? (string) $m->quantity : (string) $m->amount, 2);
            }
            $groups = array_values($groups);
            $weights = array_reduce($groups, fn (string $s, array $g) => bcadd($s, $g['weight'], 2), '0');
            if (bccomp($weights, '0', 2) <= 0) {
                throw ValidationException::withMessages(['method' => ['The goods on that bill have no value to spread by: spread by quantity.']]);
            }

            $id = (string) Str::uuid7();
            $rest = $spread;
            $toStock = Money::zero($base);
            $toSales = Money::zero($base);
            $moves = [];
            foreach ($groups as $i => $g) {
                $share = $i === count($groups) - 1 ? $rest : $spread->multipliedBy($g['weight'])->dividedBy($weights)->roundToCurrency();
                $rest = $rest->minus($share);
                // Only what is still on hand takes the cost onto its value; the rest was sold and goes to cost of sales.
                $onHand = (string) (DB::table('stock_balances')->where(['company_id' => $company->getKey(), 'item_id' => $g['item_id'], 'warehouse_id' => $g['warehouse_id']])
                    ->value('quantity') ?? '0');
                $kept = bccomp($onHand, '0', 2) <= 0 ? '0' : (bccomp($onHand, $g['quantity'], 2) >= 0 ? $g['quantity'] : $onHand);
                $stockShare = bccomp($kept, $g['quantity'], 2) === 0 ? $share : $share->multipliedBy($kept)->dividedBy($g['quantity'])->roundToCurrency();
                $toStock = $toStock->plus($stockShare);
                $toSales = $toSales->plus($share->minus($stockShare));
                if (self::positive($stockShare)) {
                    $moves[] = [$g['item_id'], $g['warehouse_id'], $stockShare];
                }
            }

            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $base, '1',
                "Landed cost: {$cost->label()} onto the goods of {$goods->label()}", 'landed_cost', $id);
            if (self::positive($toStock)) {
                $draft->add(JournalLineDraft::debit(Account::system('inventory')->getKey(), $toStock, null, "Landed cost, {$cost->label()}"));
            }
            if (self::positive($toSales)) {
                $draft->add(JournalLineDraft::debit(Account::system('cogs')->getKey(), $toSales, null, "Landed cost on goods sold, {$cost->label()}"));
            }
            // Off the accounts the cost bill was posted to, in proportion to its lines.
            $byAccount = [];
            foreach ($cost->lines as $l) {
                $byAccount[$l->account_id] = bcadd($byAccount[$l->account_id] ?? '0', (string) $l->line_subtotal, 2);
            }
            $lineTotal = array_reduce($byAccount, fn (string $s, string $v) => bcadd($s, $v, 2), '0');
            $credit = $spread;
            $accounts = array_keys($byAccount);
            foreach ($accounts as $i => $account) {
                $part = $i === count($accounts) - 1 ? $credit : $spread->multipliedBy($byAccount[$account])->dividedBy($lineTotal)->roundToCurrency();
                $credit = $credit->minus($part);
                if (! $part->isZero()) {
                    $draft->add(JournalLineDraft::credit($account, $part, null, "Landed cost moved onto the goods of {$goods->label()}"));
                }
            }
            $entry = $this->posting->post($draft, $userId);

            DB::table('landed_costs')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'cost_bill_id' => $cost->getKey(), 'goods_bill_id' => $goods->getKey(),
                'amount' => $spread->toDecimalString(), 'method' => $method, 'applied_on' => $date, 'journal_entry_id' => $entry->getKey(), 'created_by' => $userId,
            ]);
            foreach ($moves as [$item, $warehouse, $value]) {
                $this->stock->move($company->getKey(), $base, $item, $warehouse, new DateTimeImmutable($date), 'landed_cost', '0', '0',
                    $cost->reference ?? $cost->label(), $cost->getKey(), $userId, $value->toDecimalString());
            }
            $result = ['id' => $id, 'amount' => $spread->toDecimalString(), 'inventory' => $toStock->toDecimalString(), 'cost_of_sales' => $toSales->toDecimalString(),
                'journal_entry_id' => $entry->getKey()];
            $this->audit->record($company->getKey(), 'landed_cost_applied', 'bill', $cost->getKey(), null,
                [...$result, 'goods_bill' => $goods->label(), 'method' => $method, 'applied_on' => $date], userId: $userId);

            return $result;
        });
    }
}
