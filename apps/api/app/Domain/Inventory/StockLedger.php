<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Ledger\Money;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one way stock moves are written. The database values every move (024): a move in at quantity x unit cost, a move
 * out at the weighted average, and refuses stock going below zero. This returns the value the database decided, so the
 * journal entry that goes with the move uses exactly the bin card's figure.
 */
final class StockLedger
{
    /** @return array{id: string, amount: Money, unit_cost: string} the move and its value (signed, base currency) */
    public function move(string $companyId, string $baseCurrency, string $itemId, string $warehouseId, DateTimeImmutable $date, string $kind,
        string $quantity, string $unitCost, ?string $reference, ?string $documentId, ?string $userId): array
    {
        // The database refuses stock going below zero (024); saying so here gives the person a clear answer.
        if (str_starts_with(trim($quantity), '-')) {
            $onHand = (string) (DB::table('stock_balances')->where(['company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId])
                ->value('quantity') ?? '0');   // the trigger locks the balance; this read only explains a refusal early
            $allowNegative = (bool) (json_decode((string) DB::table('companies')->where('id', $companyId)->value('settings'), true)['inventory']['allow_negative'] ?? false);
            if (! $allowNegative && Money::of($onHand, $baseCurrency)->plus(Money::of($quantity, $baseCurrency))->isNegative()) {
                throw ValidationException::withMessages(['quantity' => ["Not enough stock: {$onHand} on hand."]]);
            }
        }

        $id = (string) Str::uuid7();
        try {
            DB::table('stock_moves')->insert([
                'id' => $id, 'company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
                'moved_on' => $date->format('Y-m-d'), 'kind' => $kind, 'quantity' => $quantity, 'unit_cost' => $unitCost,
                'reference' => $reference, 'document_id' => $documentId, 'created_by' => $userId,
            ]);
        } catch (QueryException $e) {
            // Two moves out at once: the second finds less on hand than it read above (check_violation, 024).
            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'Not enough stock')) {
                throw ValidationException::withMessages(['quantity' => ['Not enough stock on hand any more: someone moved it a moment ago.']]);
            }
            throw $e;
        }
        $row = DB::table('stock_moves')->where(['company_id' => $companyId, 'id' => $id])->first(['amount', 'unit_cost']);

        return ['id' => $id, 'amount' => Money::of((string) $row->amount, $baseCurrency), 'unit_cost' => (string) $row->unit_cost];
    }
}
