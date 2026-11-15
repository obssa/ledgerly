<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Sales\Models\Document;

/**
 * Duplicate-bill detection: another bill from the same supplier with the same reference, or with the same total on
 * the same date. A warning, not a block: suppliers do reuse numbers, and the person entering the bill decides.
 */
final class DuplicateBills
{
    /** @return list<array{type: string, message: string, bill_id: string}> */
    public function warnings(Document $bill): array
    {
        if ($bill->status === 'void') {
            return [];
        }

        $others = Document::query()->where(['type' => 'bill', 'contact_id' => $bill->contact_id])
            ->whereKeyNot($bill->getKey())->where('status', '<>', 'void')
            ->where(fn ($q) => $q
                // ilike with the wildcards escaped: a case-insensitive equal, and the reference never becomes SQL (CLAUDE.md rule 1).
                ->when($bill->reference !== null, fn ($w) => $w->orWhere('reference', 'ilike', addcslashes((string) $bill->reference, '%_\\')))
                ->orWhere(fn ($w) => $w->where('total', $bill->total)->whereDate('issue_date', $bill->issue_date)))
            ->limit(5)->get(['id', 'number', 'reference', 'total', 'issue_date', 'currency']);

        return $others->map(fn (Document $o) => [
            'type' => 'possible_duplicate',
            'bill_id' => $o->getKey(),
            'message' => $bill->reference !== null && $o->reference !== null && mb_strtolower($o->reference) === mb_strtolower($bill->reference)
                ? "{$o->label()} from this supplier has the same reference {$o->reference}."
                : "{$o->label()} from this supplier has the same total {$o->total} {$o->currency} on the same date.",
        ])->values()->all();
    }
}
