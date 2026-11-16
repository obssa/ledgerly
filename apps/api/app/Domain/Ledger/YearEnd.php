<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The year-end close (Module 6): the year's profit or loss goes to retained earnings with one closing entry dated the
 * year's last day (035), the books are locked through it, and the next fiscal year opens. Income and expense accounts
 * then start the new year at nothing; reports still show a closed year's profit, because the profit and loss leaves
 * closing entries out. The latest closed year can be reopened by an owner or admin with a reason: its closing entry is
 * reversed and its periods open again.
 */
final class YearEnd
{
    public function __construct(private readonly PostingService $posting)
    {
    }

    /** @return array{lines: list<array<string, string>>, net_profit: string} what closing the year would move to retained earnings */
    public function preview(Company $company, object $year): array
    {
        $base = $company->base_currency;
        $rows = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $company->getKey())->whereIn('a.type', ['income', 'expense'])->whereBetween('l.entry_date', [$year->starts_on, $year->ends_on])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->havingRaw('sum(l.debit - l.credit) <> 0')->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.type')->selectRaw('sum(l.debit - l.credit)::numeric(20,2)::text AS balance')->get();
        $net = $rows->reduce(fn (Money $t, $r) => $t->minus(Money::of($r->balance, $base)), Money::zero($base));   // income is a credit

        return ['lines' => $rows->map(fn ($r) => ['account_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'balance' => $r->balance])->values()->all(),
            'net_profit' => $net->toDecimalString()];
    }

    /** Close the year: post its closing entry, lock its periods, open the next year. */
    public function close(Company $company, string $yearId, string $userId, Membership $membership, string $today): array
    {
        return DB::transaction(function () use ($company, $yearId, $userId, $membership, $today) {
            $year = DB::table('fiscal_years')->where(['company_id' => $company->getKey(), 'id' => $yearId])->lockForUpdate()->first();
            if ($year === null) {
                abort(404);
            }
            if (! in_array($year->status, ['open', 'reopened'], true)) {
                throw ValidationException::withMessages(['year' => ["The year to {$year->ends_on} is already {$year->status}."]]);
            }
            if ($year->ends_on >= $today) {
                throw ValidationException::withMessages(['year' => ["The year ends on {$year->ends_on}: it is closed after that day."]]);
            }
            if (DB::table('fiscal_years')->where('company_id', $company->getKey())->where('ends_on', '<', $year->starts_on)->where('status', '<>', 'closed')->exists()) {
                throw ValidationException::withMessages(['year' => ['Close the earlier fiscal years first.']]);
            }
            $preview = $this->preview($company, $year);
            if ($preview['lines'] === []) {
                throw ValidationException::withMessages(['year' => ["Nothing was posted to income or expenses between {$year->starts_on} and {$year->ends_on}: there is nothing to close."]]);
            }

            // The closing entry is dated the year's last day. If the books are already locked through it, an owner or
            // admin closes the year: the lock steps back a day for the entry, then moves on to where it was (or the end).
            $locked = DB::table('companies')->where('id', $company->getKey())->value('books_closed_until');
            if ($locked !== null && $locked >= $year->ends_on) {
                if (! $membership->can('periods.reopen')) {
                    throw ValidationException::withMessages(['year' => ["The books are locked through {$locked}: an owner or admin closes this year."]]);
                }
                $this->lock($company, (new DateTimeImmutable($year->ends_on))->modify('-1 day')->format('Y-m-d'));
            }
            if ($year->status === 'open') {
                DB::table('fiscal_years')->where('id', $year->id)->update(['status' => 'closing', 'updated_at' => now()]);
            }

            $base = $company->base_currency;
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($year->ends_on), $base, $base, '1',
                "Year-end close: profit and loss for {$year->starts_on} to {$year->ends_on} to retained earnings", 'closing', $year->id);
            foreach ($preview['lines'] as $l) {
                $balance = Money::of($l['balance'], $base);
                $draft->add($balance->isNegative()
                    ? JournalLineDraft::debit($l['account_id'], $balance->negated(), null, 'Closed to retained earnings')
                    : JournalLineDraft::credit($l['account_id'], $balance, null, 'Closed to retained earnings'));
            }
            $net = Money::of($preview['net_profit'], $base);
            if (! $net->isZero()) {
                $retained = Account::system('retained_earnings')->getKey();
                $draft->add($net->isNegative()
                    ? JournalLineDraft::debit($retained, $net->negated(), null, 'Loss of the year')
                    : JournalLineDraft::credit($retained, $net, null, 'Profit of the year'));
            }
            $entry = $this->posting->post($draft, $userId);

            $this->lock($company, max((string) ($locked ?? ''), (string) $year->ends_on));
            DB::table('fiscal_years')->where('id', $year->id)->update(['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now(), 'updated_at' => now()]);

            // The next year opens, if it is not there yet.
            $next = (new FiscalCalendar($company->calendar ?: 'gregorian', (int) $company->fiscal_year_start_month))
                ->yearContaining((new DateTimeImmutable($year->ends_on))->modify('+1 day'));
            if (! DB::table('fiscal_years')->where(['company_id' => $company->getKey(), 'starts_on' => $next['start']->format('Y-m-d')])->exists()) {
                DB::table('fiscal_years')->insert(['id' => (string) Str::uuid7(), 'company_id' => $company->getKey(),
                    'starts_on' => $next['start']->format('Y-m-d'), 'ends_on' => $next['end']->format('Y-m-d')]);
            }

            return ['year' => $year->id, 'entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number], ...$preview];
        });
    }

    /** Reopen the latest closed year (owners and admins, with a reason): its closing entry is reversed and its periods open. */
    public function reopen(Company $company, string $yearId, string $userId): array
    {
        return DB::transaction(function () use ($company, $yearId, $userId) {
            $year = DB::table('fiscal_years')->where(['company_id' => $company->getKey(), 'id' => $yearId])->lockForUpdate()->first();
            if ($year === null) {
                abort(404);
            }
            if ($year->status !== 'closed') {
                throw ValidationException::withMessages(['year' => ["The year to {$year->ends_on} is not closed."]]);
            }
            if (DB::table('fiscal_years')->where('company_id', $company->getKey())->where('starts_on', '>', $year->ends_on)->where('status', 'closed')->exists()) {
                throw ValidationException::withMessages(['year' => ['Reopen the later years first: the latest closed year is reopened first.']]);
            }
            $closing = DB::table('journal_entries')->where(['company_id' => $company->getKey(), 'source_type' => 'closing', 'source_id' => $year->id, 'status' => 'posted'])->value('id');
            // The year's periods open again: the lock goes back to the day before the year's last day.
            $this->lock($company, (new DateTimeImmutable($year->ends_on))->modify('-1 day')->format('Y-m-d'));
            $reversal = $this->posting->reverse($closing, new DateTimeImmutable($year->ends_on), $userId);
            DB::table('fiscal_years')->where('id', $year->id)->update(['status' => 'reopened', 'updated_at' => now()]);

            return ['year' => $year->id, 'reversal' => ['id' => $reversal->getKey(), 'number' => (int) $reversal->number]];
        });
    }

    /** Set the period lock (the database checks who may move it back: owners and admins). */
    private function lock(Company $company, string $date): void
    {
        DB::select('select pg_advisory_xact_lock(ledger_period_lock_key(?::uuid))', [$company->getKey()]);
        DB::table('companies')->where('id', $company->getKey())->update(['books_closed_until' => $date, 'updated_at' => now()]);
    }
}
