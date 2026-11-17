<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 and 10, inventory reports from the stock moves: what came in and went out of each item in a period, and
 * how long what is on hand has waited since it last sold (slow-moving and dead stock). Values are in the base
 * currency, as the moves carry them. Every SQL fragment is fixed in code; dates and ids are bound.
 */
final class StockReports
{
    /** Days since the item last sold: the upper bound of each band, and its key. */
    public const BANDS = ['0-30' => 30, '31-90' => 90, '91-180' => 180, 'over-180' => null, 'never' => null];

    /**
     * Per stock item: on hand at the start, in and out during the period, on hand at the end, in quantity and value.
     * Across all warehouses a transfer is neither in nor out; within one warehouse it is.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function movement(string $companyId, string $from, string $to, ?string $warehouseId): array
    {
        $moves = fn () => DB::table('stock_moves as m')->where('m.company_id', $companyId)
            ->when($warehouseId, fn ($q, $w) => $q->where('m.warehouse_id', $w));
        $opening = $moves()->where('m.moved_on', '<', $from)->groupBy('m.item_id')
            ->select('m.item_id')->selectRaw('sum(m.quantity)::numeric(18,2)::text AS qty, sum(m.amount)::numeric(20,2)::text AS value')
            ->get()->keyBy('item_id');
        $period = $moves()->whereBetween('m.moved_on', [$from, $to])
            ->when($warehouseId === null, fn ($q) => $q->whereNotIn('m.kind', ['transfer_in', 'transfer_out']))
            ->groupBy('m.item_id')->select('m.item_id')
            ->selectRaw('sum(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END)::numeric(18,2)::text AS in_qty')
            ->selectRaw('sum(CASE WHEN m.quantity > 0 THEN m.amount ELSE 0 END)::numeric(20,2)::text AS in_value')
            ->selectRaw('sum(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END)::numeric(18,2)::text AS out_qty')
            ->selectRaw('sum(CASE WHEN m.quantity < 0 THEN -m.amount ELSE 0 END)::numeric(20,2)::text AS out_value')
            ->selectRaw("sum(CASE WHEN m.kind IN ('sale', 'sale_return') THEN -m.quantity ELSE 0 END)::numeric(18,2)::text AS sold_qty")
            ->get()->keyBy('item_id');
        $items = DB::table('items')->where('company_id', $companyId)->whereIn('id', $opening->keys()->merge($period->keys())->unique()->values())
            ->orderBy('name')->get(['id', 'sku', 'name', 'unit']);

        $rows = [];
        foreach ($items as $item) {
            $o = $opening[$item->id] ?? null;
            $p = $period[$item->id] ?? null;
            $row = ['item' => ['id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'unit' => $item->unit],
                'opening_qty' => $o->qty ?? '0.00', 'opening_value' => $o->value ?? '0.00',
                'in_qty' => $p->in_qty ?? '0.00', 'in_value' => $p->in_value ?? '0.00',
                'out_qty' => $p->out_qty ?? '0.00', 'out_value' => $p->out_value ?? '0.00', 'sold_qty' => $p->sold_qty ?? '0.00'];
            $row['closing_qty'] = (string) BigDecimal::of($row['opening_qty'])->plus($row['in_qty'])->minus($row['out_qty'])->toScale(2);
            $row['closing_value'] = (string) BigDecimal::of($row['opening_value'])->plus($row['in_value'])->minus($row['out_value'])->toScale(2);
            if (array_filter([$row['opening_qty'], $row['opening_value'], $row['in_qty'], $row['out_qty'], $row['closing_value']], fn ($v) => $v !== '0.00') === []) {
                continue;   // an item whose moves all came before the period and left nothing
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $this->sum($rows, ['opening_value', 'in_value', 'out_value', 'closing_value'])];
    }

    /**
     * What is on hand at a date, per stock item, with the days since it last sold and its band (0-30, 31-90, 91-180,
     * over 180 days, never sold): slow-moving stock is 91 to 180 days, dead stock over 180 or never sold.
     *
     * @return array{rows: list<array<string, mixed>>, bands: list<array<string, mixed>>, total_value: string}
     */
    public function ageing(string $companyId, string $asOf, ?string $warehouseId): array
    {
        $rows = DB::table('stock_moves as m')->join('items as i', 'i.id', '=', 'm.item_id')
            ->where('m.company_id', $companyId)->where('m.moved_on', '<=', $asOf)
            ->when($warehouseId, fn ($q, $w) => $q->where('m.warehouse_id', $w))
            ->groupBy('m.item_id', 'i.sku', 'i.name', 'i.unit')->orderBy('i.name')
            ->select('m.item_id', 'i.sku', 'i.name', 'i.unit')
            ->selectRaw('sum(m.quantity)::numeric(18,2)::text AS qty, sum(m.amount)::numeric(20,2)::text AS value')
            ->selectRaw("max(CASE WHEN m.kind = 'sale' THEN m.moved_on END)::text AS last_sold_on")
            ->selectRaw("max(CASE WHEN m.quantity > 0 AND m.kind NOT IN ('transfer_in', 'sale_return') THEN m.moved_on END)::text AS last_received_on")
            ->get()->filter(fn ($r) => BigDecimal::of($r->qty)->isGreaterThan(0));

        $at = new DateTimeImmutable($asOf);
        $out = $rows->map(function ($r) use ($at) {
            $days = $r->last_sold_on ? (int) (new DateTimeImmutable($r->last_sold_on))->diff($at)->days : null;

            return ['item' => ['id' => $r->item_id, 'sku' => $r->sku, 'name' => $r->name, 'unit' => $r->unit], 'quantity' => $r->qty, 'value' => $r->value,
                'last_sold_on' => $r->last_sold_on, 'last_received_on' => $r->last_received_on, 'days_since_sale' => $days, 'band' => self::band($days)];
        })->values()->all();

        $bands = [];
        foreach (array_keys(self::BANDS) as $key) {
            $in = array_values(array_filter($out, fn ($r) => $r['band'] === $key));
            $bands[] = ['band' => $key, 'items' => count($in), 'value' => $this->sum($in, ['value'])['value']];
        }

        return ['rows' => $out, 'bands' => $bands, 'total_value' => $this->sum($out, ['value'])['value']];
    }

    public static function band(?int $days): string
    {
        if ($days === null) {
            return 'never';
        }
        foreach (self::BANDS as $key => $upTo) {
            if ($upTo !== null && $days <= $upTo) {
                return $key;
            }
        }

        return 'over-180';
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $keys @return array<string, string> */
    private function sum(array $rows, array $keys): array
    {
        $totals = [];
        foreach ($keys as $k) {
            $totals[$k] = (string) array_reduce($rows, fn (BigDecimal $s, array $r) => $s->plus($r[$k]), BigDecimal::zero())->toScale(2);
        }

        return $totals;
    }
}
