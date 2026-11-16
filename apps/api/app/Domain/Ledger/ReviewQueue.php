<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The review queue of unusual entries (Module 6, accountant tools): entries of a period worth a second look, each with
 * why. Nothing is blocked: an entry is reviewed (once, with an optional note, 038) and leaves the queue. The tests are
 * plain and few, so a reviewer can tell at a glance why an entry is there:
 *
 *   manual_cash     a manual journal moves money on a bank, wallet or cash account (payments and transfers do that)
 *   round_amount    a manual journal of a large round amount (a multiple of 1,000, at least 10,000)
 *   large           a manual journal five times the company's average manual journal of the past year (at least five)
 *   backdated       posted more than 30 days after its date (opening balances, closes, audit adjustments,
 *                   revaluations and reversals are dated back by design)
 *   out_of_hours    posted by someone between 22:00 and 05:00 company time (recurring journals post on a schedule)
 *   opening_equity  posts to opening balance equity after the conversion date
 */
final class ReviewQueue
{
    public const FLAGS = [
        'manual_cash' => 'A manual journal moves money on a bank, wallet or cash account: payments and transfers normally do that.',
        'round_amount' => 'A large round amount on a manual journal (a multiple of 1,000, at least 10,000).',
        'large' => "Five times this company's average manual journal over the past year.",
        'backdated' => 'Posted more than 30 days after its date.',
        'out_of_hours' => 'Posted at night, between 22:00 and 05:00 company time.',
        'opening_equity' => 'Posts to opening balance equity after the conversion date.',
    ];

    public const MAX_ENTRIES = 5000;

    private const DATED_BACK_BY_DESIGN = ['opening', 'closing', 'adjustment', 'revaluation', 'reversal'];

    private const MONEY_SUBTYPES = ['bank', 'cash', 'mobile_money'];

    /**
     * The flagged entries of a period, newest first; reviewed ones only when asked.
     *
     * @return array{data: list<array<string, mixed>>, counts: array<string, int>, truncated: bool}
     */
    public function entries(Company $company, string $from, string $to, bool $withReviewed): array
    {
        $id = $company->getKey();
        $zone = $company->timezone ?: 'UTC';
        $entries = DB::table('journal_entries as e')->leftJoin('users as u', 'u.id', '=', 'e.posted_by')
            ->where('e.company_id', $id)->whereBetween('e.entry_date', [$from, $to])
            ->orderByDesc('e.number')->limit(self::MAX_ENTRIES + 1)
            ->select('e.id', 'e.number', 'e.entry_date', 'e.memo', 'e.source_type', 'e.source_id', 'e.status', 'e.posted_by', 'u.name as posted_by_name', 'e.posted_at')
            ->selectRaw("to_char(e.posted_at AT TIME ZONE ?, 'YYYY-MM-DD HH24:MI') AS posted_local", [$zone])
            ->selectRaw('(e.posted_at AT TIME ZONE ?)::date - e.entry_date AS days_late', [$zone])
            ->selectRaw('extract(hour FROM e.posted_at AT TIME ZONE ?)::int AS posted_hour', [$zone])
            ->get();
        $truncated = $entries->count() > self::MAX_ENTRIES;
        $entries = $entries->take(self::MAX_ENTRIES);
        $ids = $entries->pluck('id')->all();

        $totals = $this->lines($id, $ids)->groupBy('l.journal_entry_id')->select('l.journal_entry_id')
            ->selectRaw('sum(l.debit)::numeric(20,2)::text AS total')->pluck('total', 'journal_entry_id');
        $cash = $this->lines($id, $ids)->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.subtype', self::MONEY_SUBTYPES)
            ->distinct()->pluck('l.journal_entry_id')->flip();
        $openingEquity = $company->conversion_date === null ? collect() : $this->lines($id, $ids)->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.system_key', 'opening_balance_equity')->where('l.entry_date', '>', $company->conversion_date->format('Y-m-d'))->distinct()->pluck('l.journal_entry_id')->flip();
        $recurring = DB::table('recurring_journals')->where('company_id', $id)->pluck('id')->flip();
        $reviews = DB::table('journal_entry_reviews as r')->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')->where('r.company_id', $id)
            ->whereIn('r.journal_entry_id', $ids)->get(['r.journal_entry_id', 'u.name', 'r.reviewed_at', 'r.note', 'r.flags'])->keyBy('journal_entry_id');
        $average = $this->averageManual($id, $to);

        $data = [];
        $counts = array_fill_keys(array_keys(self::FLAGS), 0);
        foreach ($entries as $e) {
            $total = BigDecimal::of((string) ($totals[$e->id] ?? '0'));
            $manual = $e->source_type === 'manual';
            $flags = array_keys(array_filter([
                'manual_cash' => $manual && isset($cash[$e->id]),
                'round_amount' => $manual && $total->isGreaterThanOrEqualTo(10000) && $total->remainder(1000)->isZero(),
                'large' => $manual && $average !== null && $total->isGreaterThan($average->multipliedBy(5)),
                'backdated' => ! in_array($e->source_type, self::DATED_BACK_BY_DESIGN, true) && (int) $e->days_late > 30,
                'out_of_hours' => $e->posted_by !== null && ! ($manual && isset($recurring[$e->source_id ?? ''])) && ((int) $e->posted_hour >= 22 || (int) $e->posted_hour < 5),
                'opening_equity' => $e->source_type !== 'opening' && isset($openingEquity[$e->id]),
            ]));
            $review = $reviews[$e->id] ?? null;
            if ($flags === [] || ($review !== null && ! $withReviewed)) {
                continue;
            }
            foreach ($flags as $f) {
                $counts[$f]++;
            }
            $data[] = [
                'id' => $e->id, 'number' => (int) $e->number, 'date' => $e->entry_date, 'memo' => $e->memo, 'source_type' => $e->source_type,
                'status' => $e->status, 'total' => $total->toScale(2)->__toString(), 'posted_by' => $e->posted_by_name, 'posted_at' => $e->posted_local,
                'flags' => $flags,
                'review' => $review ? ['by' => $review->name, 'at' => $review->reviewed_at, 'note' => $review->note] : null,
            ];
        }

        return ['data' => $data, 'counts' => $counts, 'truncated' => $truncated];
    }

    /** The flags the queue shows for one entry now (stored with its review). @return list<string> */
    public function flagsOf(Company $company, object $entry): array
    {
        $date = substr((string) $entry->entry_date, 0, 10);
        foreach ($this->entries($company, $date, $date, true)['data'] as $row) {
            if ($row['id'] === $entry->id) {
                return $row['flags'];
            }
        }

        return [];
    }

    /** @param list<string> $ids */
    private function lines(string $companyId, array $ids): \Illuminate\Database\Query\Builder
    {
        return DB::table('journal_lines as l')->where('l.company_id', $companyId)->whereIn('l.journal_entry_id', $ids);
    }

    /** The average manual journal of the year to $to, or null with fewer than five to judge by. */
    private function averageManual(string $companyId, string $to): ?BigDecimal
    {
        $row = DB::query()->fromSub(
            DB::table('journal_lines as l')->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
                ->where(['l.company_id' => $companyId, 'e.source_type' => 'manual'])
                ->whereBetween('e.entry_date', [(new \DateTimeImmutable($to))->modify('-1 year')->format('Y-m-d'), $to])
                ->groupBy('e.id')->selectRaw('sum(l.debit) AS total'), 't')
            ->selectRaw('count(*) AS n, avg(total)::numeric(20,2)::text AS average')->first();

        return $row !== null && (int) $row->n >= 5 ? BigDecimal::of((string) $row->average) : null;
    }
}
