<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Banking\Models\BankTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Module 5: bank rules (076). Which rule fits a statement line: the first active one, by priority then age, whose words
 * are in the line's description (case does not matter), on the line's account (or any), in its direction, with its
 * size in the rule's range. The comparison is made here, in PHP, on text already read: nothing from a rule or a line
 * becomes SQL. Every query is the company's own under row-level security.
 */
final class BankRules
{
    /** @return Collection<int, object> the company's active rules, in the order they are tried */
    public function active(): Collection
    {
        return DB::table('bank_rules as r')->join('accounts as a', 'a.id', '=', 'r.target_account_id')->leftJoin('contacts as c', 'c.id', '=', 'r.contact_id')
            ->where('r.is_active', true)->orderBy('r.priority')->orderBy('r.created_at')->orderBy('r.id')
            ->get(['r.id', 'r.name', 'r.bank_account_id', 'r.direction', 'r.contains', 'r.amount_min', 'r.amount_max', 'r.target_account_id',
                'a.code as target_code', 'a.name as target_name', 'r.contact_id', 'c.name as contact_name', 'r.memo']);
    }

    /** @param Collection<int, object> $rules */
    public function fit(BankTransaction $line, Collection $rules): ?object
    {
        $text = mb_strtolower((string) $line->description);
        $amount = (string) $line->amount;
        $in = ! str_starts_with(trim($amount), '-');
        $size = ltrim(trim($amount), '-');
        foreach ($rules as $r) {
            if ($r->bank_account_id !== null && (string) $r->bank_account_id !== (string) $line->account_id) {
                continue;
            }
            if (($r->direction === 'in' && ! $in) || ($r->direction === 'out' && $in)) {
                continue;
            }
            if ($r->amount_min !== null && bccomp($size, (string) $r->amount_min, 2) < 0) {
                continue;
            }
            if ($r->amount_max !== null && bccomp($size, (string) $r->amount_max, 2) > 0) {
                continue;
            }
            if (! str_contains($text, mb_strtolower(trim((string) $r->contains)))) {
                continue;
            }

            return $r;
        }

        return null;
    }

    /** @return array{rule_id: string, name: string, account: array{id: string, code: string, name: string}, contact: ?array{id: string, name: string}, memo: ?string} */
    public static function present(object $r): array
    {
        return [
            'rule_id' => (string) $r->id, 'name' => (string) $r->name,
            'account' => ['id' => (string) $r->target_account_id, 'code' => (string) $r->target_code, 'name' => (string) $r->target_name],
            'contact' => $r->contact_id ? ['id' => (string) $r->contact_id, 'name' => (string) $r->contact_name] : null,
            'memo' => $r->memo,
        ];
    }
}
