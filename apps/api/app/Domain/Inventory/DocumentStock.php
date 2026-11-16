<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock on invoices and bills (database/schema/025_document_stock.sql). On posting:
 *   - a bill's stock lines come into its warehouse at their cost: the line's amount (with any VAT the company cannot
 *     reclaim) in the base currency, spread so the moves add up exactly to the inventory debit of the bill's entry;
 *   - an invoice's stock lines leave its warehouse at the weighted average the database decides, and the invoice's entry
 *     gains Dr Cost of goods sold, Cr Inventory for exactly that value.
 * On void, the moves are undone: goods sold come back at the cost they left with; goods bought go out at today's
 * average, and any difference from what the bill put on the inventory account is booked as an inventory adjustment, so
 * the inventory account always equals the stock on hand.
 */
final class DocumentStock
{
    public function __construct(private readonly StockLedger $stock, private readonly PostingService $posting)
    {
    }

    /** @return list<array{line: object, item: Item}> the document's lines that move stock */
    public function stockLines(Document $doc): array
    {
        $lines = $doc->relationLoaded('lines') ? $doc->lines : $doc->lines()->get();
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->filter())->where('type', 'inventory')->get()->keyBy('id');

        return $lines->filter(fn ($l) => $l->item_id !== null && isset($items[$l->item_id]))
            ->map(fn ($l) => ['line' => $l, 'item' => $items[$l->item_id]])->values()->all();
    }

    /** The warehouse a document's stock lines use; refuses a document with stock lines and no warehouse. */
    public function warehouseFor(Document $doc): Warehouse
    {
        $warehouse = $doc->warehouse_id ? Warehouse::query()->where('is_active', true)->find($doc->warehouse_id) : null;
        if ($warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => ["{$doc->label()} has stock items: choose the warehouse they " . ($doc->type === 'bill' ? 'arrive in.' : 'leave from.')]]);
        }

        return $warehouse;
    }

    /**
     * Invoice: moves its goods out and adds the cost of goods sold to its entry. Bill: moves its goods in at the cost the
     * entry puts on the inventory account. Other documents, or none with stock lines: nothing.
     */
    public function onPosting(Document $doc, JournalDraft $draft, Company $company, ?string $userId): void
    {
        $lines = $this->stockLines($doc);
        if ($lines === [] || ! in_array($doc->type, ['invoice', 'bill'], true)) {
            return;
        }
        $warehouse = $this->warehouseFor($doc);
        $base = $company->base_currency;
        $date = DateTimeImmutable::createFromInterface($doc->issue_date);

        if ($doc->type === 'invoice') {
            $cost = Money::zero($base);
            foreach ($lines as ['line' => $line, 'item' => $item]) {
                $move = $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'sale',
                    '-' . $line->quantity, '0', $doc->number, $doc->getKey(), $userId);
                $cost = $cost->plus($move['amount']->negated());
            }
            if (! $cost->isZero()) {
                $zero = Money::zero($doc->currency);
                $draft->add(JournalLineDraft::debit(Account::system('cogs')->getKey(), $cost, $zero, "Cost of goods sold, {$doc->number}"));
                $draft->add(JournalLineDraft::credit(Account::system('inventory')->getKey(), $cost, $zero, "Goods sold, {$doc->number}"));
            }

            return;
        }

        // A bill: the entry's inventory debit, spread over the stock lines by their share, the last line taking the rest.
        $inventory = Account::system('inventory')->getKey();
        $debit = Money::zero($base);
        foreach ($draft->lines() as $l) {
            if ($l->accountId === $inventory && $l->isDebit()) {
                $debit = $debit->plus($l->debit);
            }
        }
        $txnTotal = array_reduce($lines, fn (Money $s, array $l) => $s->plus($this->lineCost($l['line'], $doc, $company)), Money::zero($doc->currency));
        $left = $debit;
        foreach (array_values($lines) as $i => ['line' => $line, 'item' => $item]) {
            $share = $i === count($lines) - 1 || $txnTotal->isZero()
                ? $left
                : $debit->multipliedBy($this->lineCost($line, $doc, $company)->amount)->dividedBy($txnTotal->amount)->roundToCurrency();
            $left = $left->minus($share);
            $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'purchase',
                (string) $line->quantity, '0', $doc->reference ?? $doc->number, $doc->getKey(), $userId, $share->toDecimalString());
        }
    }

    /** Undoes a voided document's stock moves, keeping the inventory account equal to the stock on hand. */
    public function onVoid(Document $doc, Company $company, DateTimeImmutable $date, ?string $userId): void
    {
        $moves = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $doc->getKey()])
            ->whereIn('kind', ['sale', 'purchase'])->orderBy('seq')->get();
        if ($moves->isEmpty()) {
            return;
        }
        $base = $company->base_currency;
        $difference = Money::zero($base);
        foreach ($moves as $m) {
            if ($m->kind === 'sale') {
                // Back in at the cost it left with: the journal reversal puts back exactly that.
                $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, 'sale_return',
                    ltrim((string) $m->quantity, '-'), '0', "Void of {$doc->number}", $doc->getKey(), $userId, ltrim((string) $m->amount, '-'));
            } else {
                $out = $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, 'purchase_return',
                    '-' . $m->quantity, '0', "Void of {$doc->number}", $doc->getKey(), $userId);
                // The reversal takes the bill's cost off the inventory account; the stock went out at today's average.
                $difference = $difference->plus(Money::of((string) $m->amount, $base))->minus($out['amount']->negated());
            }
        }
        if ($difference->isZero()) {
            return;
        }
        $draft = new JournalDraft($company->getKey(), $date, $base, $base, '1', "Stock on void of {$doc->number}: cost against today's average", 'stock_adjustment', $doc->getKey());
        $inventory = Account::system('inventory')->getKey();
        $adjustment = Account::system('inventory_adjustment')->getKey();
        $draft->add($difference->isNegative() ? JournalLineDraft::credit($inventory, $difference->negated()) : JournalLineDraft::debit($inventory, $difference));
        $draft->add($difference->isNegative() ? JournalLineDraft::debit($adjustment, $difference->negated()) : JournalLineDraft::credit($adjustment, $difference));
        $this->posting->post($draft, $userId);
    }

    /** A bill line's cost in the document currency: its amount, with the tax when the company cannot reclaim it. */
    private function lineCost(object $line, Document $doc, Company $company): Money
    {
        $cost = Money::of((string) $line->line_subtotal, $doc->currency);
        $reclaimable = $company->vat_registered && $line->tax_rate_id !== null
            && DB::table('tax_rates')->where('id', $line->tax_rate_id)->value('kind') === 'vat';

        return $reclaimable ? $cost : $cost->plus(Money::of((string) $line->line_tax, $doc->currency));
    }
}
