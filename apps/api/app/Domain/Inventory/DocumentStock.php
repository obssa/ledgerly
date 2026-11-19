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
use App\Domain\Purchases\ReceivedGoods;
use App\Domain\Sales\DeliveredGoods;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock on invoices and bills (database/schema/025_document_stock.sql). On posting:
 *   - a bill's stock lines come into its warehouse at their cost: the line's amount (with any VAT the company cannot
 *     reclaim) in the base currency, spread so the moves add up exactly to the inventory debit of the bill's entry;
 *   - an invoice's stock lines leave its warehouse at the weighted average the database decides, and the invoice's entry
 *     gains Dr Cost of goods sold, Cr Inventory for exactly that value.
 *   - a credit note that says the goods came back (058) brings its stock lines back into the warehouse at the cost they
 *     left with on its invoice, and its entry gains Dr Inventory, Cr Cost of goods sold for exactly that value;
 *   - a supplier credit that says the goods went back (059) takes its stock lines out at the weighted average; its entry
 *     credits inventory with the line amounts, and the difference from the stock's value goes to inventory adjustment.
 * On void, the moves are undone: goods sold come back at the cost they left with; goods bought (or returned on a credit
 * note) go out at today's average, and any difference from what the entry put on the inventory account is booked as an
 * inventory adjustment, so the inventory account always equals the stock on hand.
 */
final class DocumentStock
{
    public function __construct(private readonly StockLedger $stock, private readonly PostingService $posting, private readonly ReceivedGoods $received,
        private readonly DeliveredGoods $delivered)
    {
    }

    /**
     * 072: what a line moves in the item's own unit: its quantity times its unit's size, to 2 decimals (half up). A line
     * in the item's own unit has a size of 1.
     */
    public static function baseQuantity(object $line): string
    {
        $exact = bcmul((string) $line->quantity, (string) ($line->unit_factor ?? '1'), 8);

        return bcdiv(bcadd($exact, '0.005', 8), '1', 2);
    }

    /** @return list<array{line: object, item: Item}> the document's lines that move stock */
    public function stockLines(Document $doc): array
    {
        $lines = $doc->relationLoaded('lines') ? $doc->lines : $doc->lines()->get();
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->filter())->where('type', 'inventory')->get()->keyBy('id');

        return $lines->filter(fn ($l) => $l->item_id !== null && isset($items[$l->item_id]))
            ->map(fn ($l) => ['line' => $l, 'item' => $items[$l->item_id]])->values()->all();
    }

    /** @return list<object> the document's lines selling a bundle (073) */
    private function bundleLines(Document $doc): array
    {
        $lines = $doc->relationLoaded('lines') ? $doc->lines : $doc->lines()->get();
        $bundles = Item::query()->whereIn('id', $lines->pluck('item_id')->filter())->where('type', 'bundle')->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $lines->filter(fn ($l) => $l->item_id !== null && in_array((string) $l->item_id, $bundles, true))->values()->all();
    }

    /**
     * 073: the stock parts a bundle line takes out, as lines of their own: each part's quantity per bundle times the
     * bundles sold (in the bundle's own unit, 072), in the part's own unit. Non-stock parts move nothing.
     *
     * @param list<object> $bundles
     * @return list<array{line: object, item: Item}>
     */
    private function bundleParts(array $bundles): array
    {
        $parts = DB::table('item_components')->whereIn('bundle_id', array_map(fn ($l) => $l->item_id, $bundles))->get()->groupBy('bundle_id');
        $items = Item::query()->whereIn('id', $parts->flatten()->pluck('component_id'))->where('type', 'inventory')->get()->keyBy('id');
        $out = [];
        foreach ($bundles as $line) {
            $sold = self::baseQuantity($line);
            foreach ($parts[$line->item_id] ?? [] as $p) {
                if (! isset($items[$p->component_id])) {
                    continue;
                }
                $out[] = ['line' => (object) ['line_no' => $line->line_no, 'quantity' => bcmul($sold, (string) $p->quantity, 2), 'unit_factor' => '1',
                    'source_line_id' => null, 'id' => null], 'item' => $items[$p->component_id]];
            }
        }

        return $out;
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
        // 073: a bundle sold takes its stock parts out; a bundle is never bought, nor brought back on a credit note yet.
        $bundles = $this->bundleLines($doc);
        if ($bundles !== []) {
            if (in_array($doc->type, ['bill', 'vendor_credit'], true)) {
                throw ValidationException::withMessages(['lines' => ["Line {$bundles[0]->line_no}: a bundle is sold, not bought. Buy its parts instead."]]);
            }
            if ($doc->type === 'credit_note' && $doc->restock) {
                throw ValidationException::withMessages(['lines' => ["Line {$bundles[0]->line_no}: a bundle's parts are not brought back on a credit note yet. Credit it without taking the goods back, and adjust the stock."]]);
            }
            if (in_array($doc->type, ['invoice', 'sales_receipt'], true)) {
                $lines = [...$lines, ...$this->bundleParts($bundles)];
            }
        }
        if ($lines === [] || ! in_array($doc->type, ['invoice', 'sales_receipt', 'bill', 'credit_note', 'vendor_credit'], true)
            || (in_array($doc->type, ['credit_note', 'vendor_credit'], true) && ! $doc->restock)) {
            return;
        }
        if ($doc->type === 'vendor_credit') {
            $this->sentBack($doc, $lines, $draft, $company, $userId);

            return;
        }
        if ($doc->type === 'credit_note') {
            $this->returned($doc, $lines, $draft, $company, $userId);

            return;
        }
        $warehouse = $this->warehouseFor($doc);
        $base = $company->base_currency;
        $date = DateTimeImmutable::createFromInterface($doc->issue_date);

        if (in_array($doc->type, ['invoice', 'sales_receipt'], true)) {   // a cash sale's goods leave like an invoice's (070)
            $cost = Money::zero($base);
            // 071: the items its order's delivery notes took out already left, with their cost: the invoice bills them only.
            $lines = $this->matchDelivered($doc, $lines, $company);
            foreach ($lines as ['line' => $line, 'item' => $item]) {
                $move = $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'sale',
                    '-' . self::baseQuantity($line), '0', $doc->number, $doc->getKey(), $userId);
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
        // 069: the items its order's goods received notes brought in are not brought in again; the bill clears them.
        $receivedItems = $doc->source_document_id !== null ? $this->received->items((string) $doc->source_document_id) : [];
        $cleared = [];
        foreach (array_values($lines) as $i => ['line' => $line, 'item' => $item]) {
            $share = $i === count($lines) - 1 || $txnTotal->isZero()
                ? $left
                : $debit->multipliedBy($this->lineCost($line, $doc, $company)->amount)->dividedBy($txnTotal->amount)->roundToCurrency();
            $left = $left->minus($share);
            if (in_array((string) $item->getKey(), $receivedItems, true)) {
                $cleared[] = ['line' => $line, 'item' => $item, 'share' => $share];

                continue;
            }
            $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'purchase',
                self::baseQuantity($line), '0', $doc->reference ?? $doc->number, $doc->getKey(), $userId, $share->toDecimalString(),
                batch: ['number' => $line->batch_number ?? null, 'expires_on' => $line->batch_expires_on?->format('Y-m-d') ?? null]);   // 074
        }
        if ($cleared !== []) {
            $this->clearReceived($doc, $cleared, $draft, $company, $date, $userId);
        }
    }

    /**
     * 071: an invoice made from a sales order whose delivery notes delivered some of its items. Each stock line for such
     * an item is matched to what was delivered and not yet invoiced, never more; it moves no stock and adds no cost of
     * goods sold (the note recorded both). The other stock lines are returned, to leave as usual.
     *
     * @param list<array{line: object, item: Item}> $lines
     * @return list<array{line: object, item: Item}>
     */
    private function matchDelivered(Document $doc, array $lines, Company $company): array
    {
        if ($doc->type !== 'invoice' || $doc->source_document_id === null) {
            return $lines;
        }
        $items = $this->delivered->items((string) $doc->source_document_id);
        if ($items === []) {
            return $lines;
        }
        $done = $this->delivered->byLine((string) $doc->source_document_id);
        $rest = [];
        foreach ($lines as $l) {
            ['line' => $line, 'item' => $item] = $l;
            if (! in_array((string) $item->getKey(), $items, true)) {
                $rest[] = $l;

                continue;
            }
            $orderLine = $line->source_line_id !== null ? (string) $line->source_line_id : null;
            $d = $orderLine !== null ? ($done[$orderLine] ?? null) : null;
            if ($d === null) {
                throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: {$item->name} of this order leaves on delivery notes; invoice it from the order's line."]]);
            }
            $open = bcsub($d['delivered'], $d['matched'], 2);
            if (bccomp((string) $line->quantity, $open, 2) > 0) {
                throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: only {$open} {$item->unit} of {$item->name} was delivered and not yet invoiced. Record the delivery note first."]]);
            }
            DB::table('invoice_delivery_matches')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'invoice_id' => $doc->getKey(), 'invoice_line_id' => $line->getKey(),
                'order_line_id' => $orderLine, 'quantity' => (string) $line->quantity,
            ]);
            $done[$orderLine]['matched'] = bcadd($d['matched'], (string) $line->quantity, 2);
        }

        return $rest;
    }

    /**
     * A bill's stock lines for goods its order's notes received (069). Each is matched to what was received and not yet
     * billed, never more, and takes its received value off goods received not invoiced (the bill that completes a line
     * takes all that is left of its value). The bill's price may differ from the order's: the difference goes onto the
     * goods still on hand (a price adjustment, up or down) and the rest to cost of goods sold, as a landed cost does.
     * The entry's inventory debit for these lines is taken back, so the inventory account still equals the stock.
     *
     * @param list<array{line: object, item: Item, share: Money}> $cleared
     */
    private function clearReceived(Document $doc, array $cleared, JournalDraft $draft, Company $company, DateTimeImmutable $date, ?string $userId): void
    {
        $base = $company->base_currency;
        $done = $this->received->byLine((string) $doc->source_document_id);
        $billed = Money::zero($base);
        $grni = Money::zero($base);
        $toStock = Money::zero($base);
        $toSales = Money::zero($base);
        foreach ($cleared as ['line' => $line, 'item' => $item, 'share' => $share]) {
            $orderLine = $line->source_line_id !== null ? (string) $line->source_line_id : null;
            $r = $orderLine !== null ? ($done[$orderLine] ?? null) : null;
            if ($r === null) {
                throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: {$item->name} of this order comes in on goods received notes; bill it from the order's line."]]);
            }
            $quantity = (string) $line->quantity;
            $open = bcsub($r['received'], $r['matched'], 2);
            if (bccomp($quantity, $open, 2) > 0) {
                throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: only {$open} {$item->unit} of {$item->name} was received and not yet billed. Record the goods received note first."]]);
            }
            $value = bccomp(bcadd($r['matched'], $quantity, 2), $r['received'], 2) === 0
                ? Money::of($r['received_value'], $base)->minus(Money::of($r['matched_value'], $base))
                : Money::of($r['received_value'], $base)->multipliedBy($quantity)->dividedBy($r['received'])->roundToCurrency();
            $value = $value->isNegative() ? Money::zero($base) : $value;
            DB::table('bill_receipt_matches')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'bill_id' => $doc->getKey(), 'bill_line_id' => $line->getKey(),
                'order_line_id' => $orderLine, 'quantity' => $quantity, 'amount' => $value->toDecimalString(),
            ]);
            $done[$orderLine]['matched'] = bcadd($r['matched'], $quantity, 2);
            $done[$orderLine]['matched_value'] = bcadd($r['matched_value'], $value->toDecimalString(), 2);

            // The price difference: onto what is still on hand of these goods, the rest to cost of goods sold.
            $difference = $share->minus($value);
            $kept = Money::zero($base);
            if (! $difference->isZero()) {
                $balance = DB::table('stock_balances')->where(['company_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $r['warehouse_id']])
                    ->first(['quantity', 'value']);
                $onHand = (string) ($balance->quantity ?? '0');
                $own = self::baseQuantity($line);   // 072: stock is in the item's own unit
                $have = bccomp($onHand, '0', 2) <= 0 ? '0' : (bccomp($onHand, $own, 2) >= 0 ? $own : $onHand);
                $kept = bccomp($have, $own, 2) === 0 ? $difference : $difference->multipliedBy($have)->dividedBy($own)->roundToCurrency();
                $worth = Money::of((string) ($balance->value ?? '0'), $base);
                if ($kept->isNegative() && $worth->plus($kept)->isNegative()) {
                    $kept = $worth->negated();   // never below zero: the rest to cost of goods sold
                }
                if (! $kept->isZero()) {
                    $this->stock->move($company->getKey(), $base, $item->getKey(), (string) $r['warehouse_id'], $date, 'price_adjustment', '0', '0',
                        $doc->reference ?? $doc->number, $doc->getKey(), $userId, $kept->toDecimalString());
                }
            }
            $billed = $billed->plus($share);
            $grni = $grni->plus($value);
            $toStock = $toStock->plus($kept);
            $toSales = $toSales->plus($difference->minus($kept));
        }

        $zero = Money::zero($doc->currency);
        $label = $doc->reference ?? $doc->number;
        $inventory = Account::system('inventory')->getKey();
        $back = $billed->minus($toStock);   // the bill's inventory debit for these lines, less the difference kept on the goods
        if (! $back->isZero()) {
            $draft->add($back->isNegative() ? JournalLineDraft::debit($inventory, $back->negated(), $zero, "Price difference on goods received, {$label}")
                : JournalLineDraft::credit($inventory, $back, $zero, "Goods received earlier, {$label}"));
        }
        if (! $grni->isZero()) {
            $draft->add(JournalLineDraft::debit(Account::system('grni')->getKey(), $grni, $zero, "Goods received now billed, {$label}", (string) $doc->contact_id));
        }
        if (! $toSales->isZero()) {
            $cogs = Account::system('cogs')->getKey();
            $draft->add($toSales->isNegative() ? JournalLineDraft::credit($cogs, $toSales->negated(), $zero, "Price difference on goods sold, {$label}")
                : JournalLineDraft::debit($cogs, $toSales, $zero, "Price difference on goods sold, {$label}"));
        }
    }

    /** Undoes a voided document's stock moves, keeping the inventory account equal to the stock on hand. */
    public function onVoid(Document $doc, Company $company, DateTimeImmutable $date, ?string $userId): void
    {
        // A credit note's returned goods are undone like goods bought: they go out again at today's average.
        $moves = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $doc->getKey()])
            ->whereIn('kind', match ($doc->type) { 'credit_note' => ['sale_return'], 'vendor_credit' => ['purchase_return'], default => ['sale', 'purchase', 'price_adjustment'] })
            ->orderBy('seq')->get();
        if ($moves->isEmpty()) {
            return;
        }
        $base = $company->base_currency;
        $difference = Money::zero($base);
        foreach ($moves as $m) {
            if ($m->kind === 'price_adjustment') {
                // 069: the price difference comes off the goods again, when they are still there to take it.
                $back = Money::of((string) $m->amount, $base)->negated();
                $balance = DB::table('stock_balances')->where(['company_id' => $company->getKey(), 'item_id' => $m->item_id, 'warehouse_id' => $m->warehouse_id])->first(['quantity', 'value']);
                $possible = $balance !== null && bccomp((string) $balance->quantity, '0', 2) > 0
                    && ! Money::of((string) $balance->value, $base)->plus($back)->isNegative();
                if ($possible) {
                    $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, 'price_adjustment', '0', '0',
                        "Void of {$doc->number}", $doc->getKey(), $userId, $back->toDecimalString());
                }
                // The reversal takes the difference off the inventory account; what the stock could not give back is an adjustment.
                $difference = $difference->plus($possible ? Money::zero($base) : Money::of((string) $m->amount, $base));
            } elseif ($m->kind === 'purchase_return') {
                // Goods sent back come in again at the cost they left with: the journal reversal puts back exactly that.
                $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, 'purchase',
                    ltrim((string) $m->quantity, '-'), '0', "Void of {$doc->number}", $doc->getKey(), $userId, ltrim((string) $m->amount, '-'), batchId: $m->batch_id);
            } elseif ($m->kind === 'sale') {
                // Back in at the cost it left with: the journal reversal puts back exactly that.
                $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, 'sale_return',
                    ltrim((string) $m->quantity, '-'), '0', "Void of {$doc->number}", $doc->getKey(), $userId, ltrim((string) $m->amount, '-'), batchId: $m->batch_id);
            } else {
                $out = $this->stock->move($company->getKey(), $base, $m->item_id, $m->warehouse_id, $date, $m->kind === 'sale_return' ? 'sale' : 'purchase_return',
                    '-' . $m->quantity, '0', "Void of {$doc->number}", $doc->getKey(), $userId, batchId: $m->batch_id);
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

    /**
     * Goods back on a credit note: each stock line comes back at the cost per unit its item left with on the invoice
     * (the whole of what is left when everything comes back, so the cost never drifts), and never more than the invoice
     * sold (the database checks it too, 058). The cost goes from cost of goods sold back onto inventory.
     *
     * @param list<array{line: object, item: Item}> $lines
     */
    private function returned(Document $doc, array $lines, JournalDraft $draft, Company $company, ?string $userId): void
    {
        $base = $company->base_currency;
        $warehouse = $this->warehouseFor($doc);
        $original = Document::query()->findOrFail($doc->original_document_id);
        $sold = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $original->getKey(), 'kind' => 'sale'])
            ->get(['item_id', 'quantity', 'amount'])->groupBy('item_id');
        $back = DB::table('stock_moves as m')->join('documents as c', 'c.id', '=', 'm.document_id')
            ->where(['m.company_id' => $company->getKey(), 'c.type' => 'credit_note', 'c.original_document_id' => $original->getKey()])
            ->whereIn('m.kind', ['sale_return', 'sale'])->get(['m.item_id', 'm.quantity', 'm.amount'])->groupBy('item_id');
        $date = DateTimeImmutable::createFromInterface($doc->issue_date);
        $cost = Money::zero($base);
        foreach ($lines as ['line' => $line, 'item' => $item]) {
            $moves = $sold[$item->getKey()] ?? collect();
            $soldQty = $moves->reduce(fn (string $s, $m) => bcsub($s, (string) $m->quantity, 2), '0');
            $soldCost = $moves->reduce(fn (string $s, $m) => bcsub($s, (string) $m->amount, 2), '0');
            $backQty = ($back[$item->getKey()] ?? collect())->reduce(fn (string $s, $m) => bcadd($s, (string) $m->quantity, 2), '0');
            $backCost = ($back[$item->getKey()] ?? collect())->reduce(fn (string $s, $m) => bcadd($s, (string) $m->amount, 2), '0');
            $left = bcsub($soldQty, $backQty, 2);
            $qty = self::baseQuantity($line);   // 072
            if (bccomp($qty, $left, 2) > 0) {
                throw ValidationException::withMessages(['lines' => [bccomp($soldQty, '0', 2) === 0
                    ? "{$item->name} was not sold on {$original->number}: it cannot come back on this credit note."
                    : "Only {$left} {$item->unit} of {$item->name} can still come back from {$original->number}."]]);
            }
            $value = bccomp($qty, $left, 2) === 0
                ? Money::of(bcsub($soldCost, $backCost, 2), $base)
                : Money::of($soldCost, $base)->multipliedBy($qty)->dividedBy($soldQty)->roundToCurrency();
            $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'sale_return',
                $qty, '0', $doc->number, $doc->getKey(), $userId, $value->toDecimalString(),
                batch: ['number' => $line->batch_number ?? null, 'expires_on' => $line->batch_expires_on?->format('Y-m-d') ?? null]);   // 074: the batch it comes back as
            $cost = $cost->plus($value);
        }
        if (! $cost->isZero()) {
            $zero = Money::zero($doc->currency);
            $draft->add(JournalLineDraft::debit(Account::system('inventory')->getKey(), $cost, $zero, "Goods returned, {$doc->number}"));
            $draft->add(JournalLineDraft::credit(Account::system('cogs')->getKey(), $cost, $zero, "Cost of goods returned, {$doc->number}"));
        }
    }

    /**
     * Goods sent back on a supplier credit: each stock line leaves the bill's warehouse at today's average, never more than
     * the bill brought in (the database checks it too, 059). The entry already credits inventory with the line amounts;
     * the difference from the value that left goes between inventory and inventory adjustment.
     *
     * @param list<array{line: object, item: Item}> $lines
     */
    private function sentBack(Document $doc, array $lines, JournalDraft $draft, Company $company, ?string $userId): void
    {
        $base = $company->base_currency;
        $warehouse = $this->warehouseFor($doc);
        $bill = Document::query()->findOrFail($doc->original_document_id);
        $received = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'document_id' => $bill->getKey(), 'kind' => 'purchase'])
            ->get(['item_id', 'quantity'])->groupBy('item_id');
        $gone = DB::table('stock_moves as m')->join('documents as c', 'c.id', '=', 'm.document_id')
            ->where(['m.company_id' => $company->getKey(), 'c.type' => 'vendor_credit', 'c.original_document_id' => $bill->getKey()])
            ->whereIn('m.kind', ['purchase_return', 'purchase'])->get(['m.item_id', 'm.quantity'])->groupBy('item_id');
        $date = DateTimeImmutable::createFromInterface($doc->issue_date);
        $out = Money::zero($base);
        foreach ($lines as ['line' => $line, 'item' => $item]) {
            $in = ($received[$item->getKey()] ?? collect())->reduce(fn (string $s, $m) => bcadd($s, (string) $m->quantity, 2), '0');
            $back = ($gone[$item->getKey()] ?? collect())->reduce(fn (string $s, $m) => bcsub($s, (string) $m->quantity, 2), '0');
            $left = bcsub($in, $back, 2);
            $qty = self::baseQuantity($line);   // 072
            if (bccomp($qty, $left, 2) > 0) {
                throw ValidationException::withMessages(['lines' => [bccomp($in, '0', 2) === 0
                    ? "{$item->name} did not come in on {$bill->label()}: it cannot go back on this credit."
                    : "Only {$left} {$item->unit} of {$item->name} can still go back from {$bill->label()}."]]);
            }
            $move = $this->stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'purchase_return',
                '-' . $qty, '0', $doc->reference ?? $doc->number, $doc->getKey(), $userId);
            $out = $out->plus($move['amount']->negated());
        }
        $inventory = Account::system('inventory')->getKey();
        $credited = Money::zero($base);
        foreach ($draft->lines() as $l) {
            if ($l->accountId === $inventory && ! $l->isDebit()) {
                $credited = $credited->plus($l->credit);
            }
        }
        // The stock fell by $out; the entry takes $credited off the inventory account. The rest is an adjustment.
        $difference = $out->minus($credited);
        if (! $difference->isZero()) {
            $zero = Money::zero($doc->currency);
            $adjustment = Account::system('inventory_adjustment')->getKey();
            $memo = "Goods back to the supplier at their cost, {$doc->label()}";
            $draft->add($difference->isNegative() ? JournalLineDraft::debit($inventory, $difference->negated(), $zero, $memo) : JournalLineDraft::credit($inventory, $difference, $zero, $memo));
            $draft->add($difference->isNegative() ? JournalLineDraft::credit($adjustment, $difference->negated(), $zero, $memo) : JournalLineDraft::debit($adjustment, $difference, $zero, $memo));
        }
    }

    /** A bill or purchase order line's cost in the document currency: its amount, with the tax when the company cannot reclaim it. */
    public function lineCost(object $line, Document $doc, Company $company): Money
    {
        $cost = Money::of((string) $line->line_subtotal, $doc->currency);
        $reclaimable = $company->vat_registered && $line->tax_rate_id !== null
            && DB::table('tax_rates')->where('id', $line->tax_rate_id)->value('kind') === 'vat';

        return $reclaimable ? $cost : $cost->plus(Money::of((string) $line->line_tax, $doc->currency));
    }
}
