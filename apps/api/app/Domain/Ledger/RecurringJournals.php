<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring manual journals (Module 6, 036): a template's lines posted on a schedule, monthly, quarterly or yearly, as an
 * ordinary manual journal naming the template. Each run posts every date that has come due (at most 24 at once, so a
 * template left for years does not flood the books), then moves the next date on; past its end date the template stops.
 *
 * A template that can no longer post (an account closed, a period locked, the total now above the approval limit, the
 * person posting no longer allowed) is paused with the reason, so it is put right and resumed rather than retried in vain.
 */
final class RecurringJournals
{
    /** Months between postings. */
    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const CATCH_UP = 24;

    public function __construct(private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, list<mixed>> validation rules for a template */
    public static function rules(): array
    {
        $journal = ManualJournal::rules('2999-12-31');
        unset($journal['date']);
        $journal['lines'] = ['required', 'array', 'min:2', 'max:200'];

        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            ...$journal,
            'frequency' => ['required', 'in:' . implode(',', array_keys(self::FREQUENCIES))],
            'next_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:next_on'],
        ];
    }

    /**
     * The date after $on at the template's frequency. A month-end date stays at month-end (31 January, 28 February,
     * 31 March); any other day keeps its day, or the month's last day when the month is shorter.
     */
    public static function advance(string $on, string $frequency): string
    {
        $date = new DateTimeImmutable($on);
        $months = self::FREQUENCIES[$frequency];
        $first = $date->modify('first day of this month')->modify("+{$months} months");
        $last = (int) $first->format('t');
        $day = $date->format('d') === $date->format('t') ? $last : min((int) $date->format('d'), $last);

        return $first->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Post what has come due on the template by $today, as $userId. Runs inside the company's tenant transaction.
     *
     * @return array{posted: list<int>, error: ?string} the entry numbers posted, and why the template paused if it did
     */
    public function postDue(Company $company, string $templateId, string $userId, string $today): array
    {
        $t = DB::table('recurring_journals')->where(['company_id' => $company->getKey(), 'id' => $templateId])->lockForUpdate()->first();
        if ($t === null || ! $t->is_active || $t->next_on > $today) {
            return ['posted' => [], 'error' => null];
        }
        $lines = json_decode((string) $t->lines, true, 512, JSON_THROW_ON_ERROR);
        $next = (string) $t->next_on;
        $posted = [];
        $error = $this->cannotPost($company, $t, $userId);

        while ($error === null && $next <= $today && count($posted) < self::CATCH_UP && ($t->ends_on === null || $next <= $t->ends_on)) {
            try {
                $entry = DB::transaction(fn () => $this->posting->post(ManualJournal::draft($company, $next, $t->memo, $lines, $t->id), $userId));
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first() ?? 'The journal no longer checks out.';
                break;
            } catch (LedgerException $e) {
                $error = $e->getMessage();
                break;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23514') {
                    throw $e;
                }
                $error = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The books refused this entry.';
                break;
            }
            $posted[] = (int) $entry->number;
            $this->audit->record($company->getKey(), 'recurring_journal_posted', 'recurring_journal', $t->id, null,
                ['date' => $next, 'journal_entry' => (int) $entry->number, 'total' => (string) $t->total], userId: $userId);
            $next = self::advance($next, $t->frequency);
        }

        $ended = $t->ends_on !== null && $next > $t->ends_on;
        $update = ['next_on' => $next, 'updated_at' => now(), 'is_active' => $error === null && ! $ended,
            'last_error' => $error === null ? null : mb_substr($error, 0, 500)];
        if ($posted !== []) {
            $update['posted_count'] = $t->posted_count + count($posted);
            $update['last_posted_on'] = DB::table('journal_entries')->where(['company_id' => $company->getKey(), 'number' => end($posted)])->value('entry_date');
        }
        DB::table('recurring_journals')->where(['company_id' => $company->getKey(), 'id' => $t->id])->update($update);
        if ($error !== null) {
            $this->audit->record($company->getKey(), 'recurring_journal_paused', 'recurring_journal', $t->id, ['is_active' => true],
                ['is_active' => false, 'next_on' => $next], $error, userId: $userId);
        }

        return ['posted' => $posted, 'error' => $error];
    }

    /** Why the template cannot post as $userId at all, or null. */
    private function cannotPost(Company $company, object $t, string $userId): ?string
    {
        $membership = Membership::query()->where(['company_id' => $company->getKey(), 'user_id' => $userId, 'status' => 'active'])->first();
        if ($membership === null || ! $membership->can('journals.create')) {
            return 'The person posting it can no longer post journals: edit the template and resume it as someone who can.';
        }
        $limit = ManualJournal::approvalLimit($company);
        if ($limit !== null && $limit->minus(Money::of((string) $t->total, $company->base_currency))->isNegative()) {
            return "Its total ({$t->total}) is above the approval limit for journals ({$limit->toDecimalString()}): a recurring journal posts without approval.";
        }

        return null;
    }
}
