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
 *
 * 074: an item tracked by batch moves by batch. A move in names its batch: an existing one ($batchId) or one given by
 * number and expiry ($batch), made the first time it is seen. A move out takes the batch named, or else the batches that
 * expire first, as many moves as it takes (one per batch); what is returned is their total. 096: an item tracked by
 * serial number is a batch of one per unit: a move of several units lists their serial numbers (one move each), and
 * goods out without them take the oldest in stock.
 */
final class StockLedger
{
    /**
     * @param array{number?: ?string, expires_on?: ?string}|null $batch
     * @return array{id: string, amount: Money, unit_cost: string, parts: list<array{batch_id: ?string, quantity: string, amount: string}>}
     *         the first move, the value of them all (signed, base currency), and each move by batch
     */
    public function move(string $companyId, string $baseCurrency, string $itemId, string $warehouseId, DateTimeImmutable $date, string $kind,
        string $quantity, string $unitCost, ?string $reference, ?string $documentId, ?string $userId, ?string $amount = null, ?string $transferGroup = null,
        ?string $projectId = null, ?string $goodsReceiptId = null, ?string $deliveryNoteId = null, ?string $batchId = null, ?array $batch = null,
        ?string $assemblyBuildId = null): array
    {
        $out = str_starts_with(trim($quantity), '-');
        // The database refuses stock going below zero (024); saying so here gives the person a clear answer.
        if ($out) {
            $onHand = (string) (DB::table('stock_balances')->where(['company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId])
                ->value('quantity') ?? '0');   // the trigger locks the balance; this read only explains a refusal early
            $allowNegative = (bool) (json_decode((string) DB::table('companies')->where('id', $companyId)->value('settings'), true)['inventory']['allow_negative'] ?? false);
            if (! $allowNegative && Money::of($onHand, $baseCurrency)->plus(Money::of($quantity, $baseCurrency))->isNegative()) {
                throw ValidationException::withMessages(['quantity' => ["Not enough stock: {$onHand} on hand."]]);
            }
        }

        $item = DB::table('items')->where(['company_id' => $companyId, 'id' => $itemId])->first(['name', 'tracking']);
        $tracked = in_array($item->tracking ?? 'none', ['batch', 'serial'], true) && bccomp(ltrim($quantity, '-'), '0', 2) !== 0;
        $row = fn (?string $b, string $q, ?string $a) => [
            'company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
            'moved_on' => $date->format('Y-m-d'), 'kind' => $kind, 'quantity' => $q, 'unit_cost' => $unitCost,
            'reference' => $reference, 'document_id' => $documentId, 'created_by' => $userId,
            'amount' => $a,   // stock coming in at a known value; the database values moves out itself
            'transfer_group' => $transferGroup,
            'project_id' => $projectId,   // stock issued to a project (055)
            'goods_receipt_id' => $goodsReceiptId,   // the goods received note's own moves (069)
            'delivery_note_id' => $deliveryNoteId,   // the delivery note's own moves (071)
            'batch_id' => $b,   // 074
            'assembly_build_id' => $assemblyBuildId,   // the build's own moves (087)
        ];

        if (! $tracked) {
            return $this->write([$row(null, $quantity, $amount)], $companyId, $baseCurrency);
        }
        if ($item->tracking === 'serial' && $batchId === null) {
            $units = ltrim($quantity, '-');
            if (bccomp($units, bcadd($units, '0', 0), 2) !== 0) {
                throw ValidationException::withMessages(['quantity' => ["{$item->name} is tracked by serial number: it moves in whole units."]]);
            }
            $numbers = self::serials($batch['number'] ?? null);
            if ($numbers === [] && ! $out) {
                throw ValidationException::withMessages(['batch' => ["{$item->name} is tracked by serial number: give the serial number of each unit that comes in."]]);
            }
            if ($numbers !== []) {
                if (count($numbers) !== (int) $units) {
                    throw ValidationException::withMessages(['batch' => [count($numbers) . " serial numbers for {$units} units of {$item->name}: one each."]]);
                }
                // Coming in at a known value: shared by unit, the last taking what rounding leaves.
                $share = $amount === null ? null : bcdiv($amount, (string) count($numbers), 2);
                $rows = [];
                foreach ($numbers as $i => $n) {
                    $id = $this->serialFor($companyId, $itemId, (string) $item->name, $n, $out);
                    $part = $share === null ? null : ($i === count($numbers) - 1 ? bcsub($amount, bcmul($share, (string) (count($numbers) - 1), 2), 2) : $share);
                    $rows[] = $row($id, $out ? '-1' : '1', $part);
                }

                return $this->write($rows, $companyId, $baseCurrency);
            }
            // Out without serial numbers: the oldest in stock, one unit each (below).
        }
        if (! $out) {
            return $this->write([$row($batchId ?? $this->batchFor($companyId, $itemId, (string) $item->name, $batch), $quantity, $amount)], $companyId, $baseCurrency);
        }
        if ($batchId === null && trim((string) ($batch['number'] ?? '')) !== '') {
            // Out of a batch named by its number: one that exists.
            $batchId = DB::table('stock_batches')->where(['company_id' => $companyId, 'item_id' => $itemId])
                ->whereRaw('upper(btrim(number)) = ?', [mb_strtoupper(trim((string) $batch['number']))])->value('id')
                ?? throw ValidationException::withMessages(['batch' => ["{$item->name} has no batch {$batch['number']}."]]);
        }
        if ($batchId !== null) {
            return $this->write([$row((string) $batchId, $quantity, $amount)], $companyId, $baseCurrency);
        }

        // Out, first expiring first (no expiry last), then the oldest batch. The database locks each batch's stock as the
        // moves are written and refuses one going below zero: a move out at the same moment is told to try again.
        $need = ltrim($quantity, '-');
        $rows = [];
        $available = DB::table('batch_balances as bb')->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
            ->where(['bb.company_id' => $companyId, 'bb.item_id' => $itemId, 'bb.warehouse_id' => $warehouseId])->where('bb.quantity', '>', 0)
            ->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('b.created_at')->orderBy('b.id')->get(['bb.batch_id', 'bb.quantity']);   // ids are time-ordered: the first made first
        foreach ($available as $a) {
            if (bccomp($need, '0', 2) <= 0) {
                break;
            }
            $take = bccomp((string) $a->quantity, $need, 2) >= 0 ? $need : (string) $a->quantity;
            $rows[] = $row((string) $a->batch_id, '-' . $take, null);
            $need = bcsub($need, $take, 2);
        }
        if (bccomp($need, '0', 2) > 0) {
            $have = bcsub(ltrim($quantity, '-'), $need, 2);
            throw ValidationException::withMessages(['quantity' => ["Not enough stock of {$item->name} in its batches here: {$have} on hand."]]);
        }

        return $this->write($rows, $companyId, $baseCurrency);
    }

    /** 096: the serial numbers a line lists, by comma, semicolon or new line, each once. @return list<string> */
    public static function serials(?string $listed): array
    {
        $numbers = array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/', (string) $listed) ?: []), fn ($n) => $n !== ''));
        $seen = [];
        foreach ($numbers as $n) {
            if (mb_strlen($n) > 60) {
                throw ValidationException::withMessages(['batch' => ["Serial number {$n} is longer than 60 characters."]]);
            }
            if (isset($seen[mb_strtoupper($n)])) {
                throw ValidationException::withMessages(['batch' => ["Serial number {$n} is listed twice."]]);
            }
            $seen[mb_strtoupper($n)] = true;
        }

        return $numbers;
    }

    /** 096: a serial of the item: one in stock to go out, or (coming in) one not in stock, made the first time it is seen. */
    private function serialFor(string $companyId, string $itemId, string $name, string $number, bool $out): string
    {
        $id = DB::table('stock_batches')->where(['company_id' => $companyId, 'item_id' => $itemId])
            ->whereRaw('upper(btrim(number)) = ?', [mb_strtoupper($number)])->value('id');   // bound, as 074's lookup
        $held = $id === null ? '0' : (string) DB::table('batch_balances')->where(['company_id' => $companyId, 'batch_id' => $id])->sum('quantity');
        if ($out && bccomp($held, '0', 2) <= 0) {
            throw ValidationException::withMessages(['batch' => ["{$name} {$number} is not in stock."]]);
        }
        if (! $out && bccomp($held, '0', 2) > 0) {
            throw ValidationException::withMessages(['batch' => ["{$name} {$number} is in stock already."]]);
        }
        if ($id !== null) {
            return (string) $id;
        }
        $id = (string) Str::uuid7();
        DB::table('stock_batches')->insert(['id' => $id, 'company_id' => $companyId, 'item_id' => $itemId, 'number' => $number]);

        return $id;
    }

    /**
     * The batch a move in names, by its number (made the first time it is seen, with its expiry).
     *
     * @param array{number?: ?string, expires_on?: ?string}|null $batch
     */
    private function batchFor(string $companyId, string $itemId, string $name, ?array $batch): string
    {
        $number = trim((string) ($batch['number'] ?? ''));
        if ($number === '') {
            throw ValidationException::withMessages(['batch' => ["{$name} is tracked by batch: give the batch number of what comes in."]]);
        }
        $expires = $batch['expires_on'] ?? null;
        $found = DB::table('stock_batches')->where(['company_id' => $companyId, 'item_id' => $itemId])->whereRaw('upper(btrim(number)) = ?', [mb_strtoupper($number)])
            ->first(['id', 'expires_on']);
        if ($found !== null) {
            if ($expires !== null && $found->expires_on !== null && (string) $found->expires_on !== $expires) {
                throw ValidationException::withMessages(['batch' => ["Batch {$number} of {$name} expires on {$found->expires_on}, not {$expires}."]]);
            }
            if ($expires !== null && $found->expires_on === null) {
                DB::table('stock_batches')->where('id', $found->id)->update(['expires_on' => $expires]);
            }

            return (string) $found->id;
        }
        $id = (string) Str::uuid7();
        DB::table('stock_batches')->insert(['id' => $id, 'company_id' => $companyId, 'item_id' => $itemId, 'number' => $number, 'expires_on' => $expires]);

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{id: string, amount: Money, unit_cost: string, parts: list<array{batch_id: ?string, quantity: string, amount: string}>}
     */
    private function write(array $rows, string $companyId, string $baseCurrency): array
    {
        $ids = [];
        try {
            foreach ($rows as $r) {
                $id = (string) Str::uuid7();
                DB::table('stock_moves')->insert(['id' => $id, ...$r]);
                $ids[] = $id;
            }
        } catch (QueryException $e) {
            // Two moves out at once: the second finds less on hand than it read above (check_violation, 024, 074).
            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'Not enough stock')) {
                throw ValidationException::withMessages(['quantity' => ['Not enough stock on hand any more: someone moved it a moment ago.']]);
            }
            throw $e;
        }
        $written = DB::table('stock_moves')->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('seq')->get(['id', 'amount', 'unit_cost', 'batch_id', 'quantity']);
        $total = $written->reduce(fn (Money $s, $m) => $s->plus(Money::of((string) $m->amount, $baseCurrency)), Money::zero($baseCurrency));
        $first = $written->first();

        return ['id' => (string) $first->id, 'amount' => $total, 'unit_cost' => (string) $first->unit_cost,
            'parts' => $written->map(fn ($m) => ['batch_id' => $m->batch_id, 'quantity' => (string) $m->quantity, 'amount' => (string) $m->amount])->values()->all()];
    }
}
