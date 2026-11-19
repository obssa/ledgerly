<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Ledger\Money;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 4: assemblies (087). A stock item made of other stock items: its parts list says how many of each go into one.
 * Building some takes the parts out of a warehouse at their cost (weighted average or FIFO, decided by the database) and
 * puts the item in at the sum of that cost. Value moves; none is made or lost, so the inventory account is untouched and
 * nothing is posted. A build is kept: a mistake is corrected with a stock adjustment.
 */
final class Assemblies
{
    public function __construct(private readonly CurrentCompany $current, private readonly StockLedger $stock, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array{part_id: string, name: string, unit: string, quantity: string}> */
    public function parts(Item $item): array
    {
        return DB::table('item_assembly_parts as p')->join('items as i', 'i.id', '=', 'p.part_id')->where('p.assembly_id', $item->getKey())
            ->orderBy('i.name')->get(['p.part_id', 'i.name', 'i.unit', 'p.quantity'])
            ->map(fn ($p) => ['part_id' => (string) $p->part_id, 'name' => (string) $p->name, 'unit' => (string) $p->unit, 'quantity' => rtrim(rtrim((string) $p->quantity, '0'), '.')])->all();
    }

    /**
     * Replaces the item's parts list.
     *
     * @param list<array{item_id: string, quantity: string}> $parts
     */
    public function setParts(Item $item, array $parts, string $userId): array
    {
        if ($item->type !== 'inventory' || $item->tracking !== 'none') {
            throw ValidationException::withMessages(['parts' => ["{$item->name} is not a stock item counted without batches: only such an item is assembled."]]);
        }
        $ids = array_column($parts, 'item_id');
        // Company-scoped: another company's item is simply not found.
        $found = Item::query()->whereIn('id', $ids)->where('type', 'inventory')->pluck('name', 'id');
        foreach ($parts as $i => $p) {
            if (! isset($found[$p['item_id']]) || $p['item_id'] === $item->getKey()) {
                throw ValidationException::withMessages(["parts.{$i}.item_id" => ['A part is another of the company\'s stock items.']]);
            }
        }
        $before = $this->parts($item);
        DB::transaction(function () use ($item, $parts) {
            DB::table('item_assembly_parts')->where('assembly_id', $item->getKey())->delete();
            foreach ($parts as $p) {
                DB::table('item_assembly_parts')->insert(['company_id' => $item->company_id, 'assembly_id' => $item->getKey(),
                    'part_id' => $p['item_id'], 'quantity' => (string) $p['quantity']]);
            }
        });
        $this->audit->record($this->current->id(), 'assembly_parts_set', 'item', $item->getKey(), ['parts' => $before], ['parts' => $this->parts($item)], userId: $userId);

        return $this->parts($item);
    }

    /** @return array<string, mixed> the build */
    public function build(Item $item, Warehouse $warehouse, string $quantity, string $date, ?string $reference, string $userId): array
    {
        $parts = $this->parts($item);
        if ($parts === []) {
            throw ValidationException::withMessages(['quantity' => ["{$item->name} has no parts list: give it its parts first."]]);
        }
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($item, $warehouse, $quantity, $date, $reference, $userId, $parts, $company, $base) {
            $id = (string) Str::uuid7();
            DB::table('assembly_builds')->insert(['id' => $id, 'company_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(),
                'quantity' => $quantity, 'built_on' => $date, 'reference' => $reference, 'created_by' => $userId]);
            $at = new DateTimeImmutable($date);
            $label = mb_substr('Built: ' . ($reference ?: $item->name), 0, 100);
            $value = Money::zero($base);
            $used = [];
            foreach ($parts as $p) {
                $need = bcmul($quantity, $p['quantity'], 2);
                if (bccomp($need, '0', 2) <= 0) {
                    continue;
                }
                $move = $this->stock->move($company->getKey(), $base, $p['part_id'], $warehouse->getKey(), $at, 'production', '-' . $need, '0', $label, null, $userId,
                    assemblyBuildId: $id);
                $cost = $move['amount']->isNegative() ? $move['amount']->negated() : $move['amount'];   // a move out is valued negative
                $value = $value->plus($cost);
                $used[] = ['item' => $p['name'], 'quantity' => $need, 'value' => $cost->toDecimalString()];
            }
            $unit = bccomp($quantity, '0', 2) > 0 ? bcdiv($value->toDecimalString(), $quantity, 6) : '0';
            $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $at, 'production', $quantity, $unit, $label, null, $userId,
                $value->toDecimalString(), assemblyBuildId: $id);
            DB::table('assembly_builds')->where('id', $id)->update(['value' => $value->toDecimalString()]);
            $this->audit->record($company->getKey(), 'assembly_built', 'item', $item->getKey(), null,
                ['build_id' => $id, 'quantity' => $quantity, 'warehouse' => $warehouse->name, 'date' => $date, 'value' => $value->toDecimalString(), 'parts' => $used], userId: $userId);

            return ['id' => $id, 'item_id' => (string) $item->getKey(), 'quantity' => $quantity, 'built_on' => $date, 'value' => $value->toDecimalString(), 'parts' => $used];
        });
    }

    /** @return list<array<string, mixed>> */
    public function builds(Item $item): array
    {
        return DB::table('assembly_builds as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('users as u', 'u.id', '=', 'b.created_by')
            ->where('b.item_id', $item->getKey())->orderByDesc('b.built_on')->orderByDesc('b.created_at')->limit(100)
            ->get(['b.id', 'b.quantity', 'b.built_on', 'b.reference', 'b.value', 'w.name as warehouse', 'u.name as built_by'])->map(fn ($b) => (array) $b)->all();
    }
}
