<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\PeriodLocked;
use App\Domain\Ledger\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code path that writes to journal_entries / journal_lines.
 * Modules (sales, purchases, banking, inventory) build a JournalDraft and hand it here.
 *
 * Locks, always in this order so two postings can never deadlock:
 *   1. the company's period, SHARED: many postings run side by side; closing a period takes it
 *      exclusively, so a posting and a close can never slip past each other
 *   2. the company's journal number sequence row: the instant that makes numbers gapless
 * The company row itself is never locked, so users of one company do not queue behind each other for
 * anything except the number, and companies never wait for one another.
 * The database keeps account_balances in step in the same transaction (002_ledger.sql).
 */
final class PostingService
{
    public function post(JournalDraft $draft, ?string $userId = null): JournalEntry
    {
        $draft->assertBalanced();

        return DB::transaction(function () use ($draft, $userId) {
            DB::select('select pg_advisory_xact_lock_shared(ledger_period_lock_key(?::uuid))', [$draft->companyId]);

            // Read after the lock: a close that was in flight has committed by now. The database checks the
            // same rule (and the subscription and company state) in its own trigger.
            $closedUntil = DB::table('companies')->where('id', $draft->companyId)->value('books_closed_until');
            $date = $draft->date->format('Y-m-d');

            if ($closedUntil !== null && $date <= $closedUntil) {
                throw PeriodLocked::until($closedUntil, $date);
            }

            $this->assertAccountsUsable($draft);
            // A reconciled period is locked per account (028): the database refuses it too; this says it plainly.
            $accountIds = array_values(array_unique(array_map(fn (JournalLineDraft $l) => $l->accountId, $draft->lines())));
            $reconciled = DB::table('reconciliations as r')->join('accounts as a', 'a.id', '=', 'r.account_id')
                ->where(['r.company_id' => $draft->companyId, 'r.status' => 'completed'])->whereIn('r.account_id', $accountIds)
                ->where('r.statement_date', '>=', $date)->orderByDesc('r.statement_date')->first(['a.code', 'a.name', 'r.statement_date']);
            if ($reconciled !== null) {
                throw new LedgerException("{$reconciled->code} {$reconciled->name} is reconciled through {$reconciled->statement_date}: date the entry after that, or undo the reconciliation.");
            }

            $entryId = (string) Str::uuid7(); // time-ordered: new rows land together in the index
            $number = $this->nextNumber($draft->companyId, 'journal');

            DB::table('journal_entries')->insert([
                'id' => $entryId,
                'company_id' => $draft->companyId,
                'number' => $number,
                'entry_date' => $date,
                'memo' => $draft->memo,
                'source_type' => $draft->sourceType,
                'source_id' => $draft->sourceId,
                'currency' => $draft->currency,
                'fx_rate' => $draft->fxRate,
                'posted_by' => $userId,
            ]);

            $rows = [];
            foreach ($draft->lines() as $i => $line) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $draft->companyId,
                    'journal_entry_id' => $entryId,
                    'line_no' => $i + 1,
                    'account_id' => $line->accountId,
                    'description' => $line->description,
                    'debit' => $line->debit->toDecimalString(),
                    'credit' => $line->credit->toDecimalString(),
                    'amount_txn' => $line->amountTxn->toDecimalString(),
                    'contact_id' => $line->contactId,
                    'location_id' => $line->dimensions['location_id'] ?? null,
                    'class_id' => $line->dimensions['class_id'] ?? null,
                    'project_id' => $line->dimensions['project_id'] ?? null,
                ];
            }
            DB::table('journal_lines')->insert($rows); // one statement: the balances trigger runs once per entry

            DB::table('outbox_events')->insert([
                'company_id' => $draft->companyId,
                'event_type' => 'journal_entry.posted',
                'aggregate_type' => 'journal_entry',
                'aggregate_id' => $entryId,
                'payload' => json_encode([
                    'number' => $number,
                    'date' => $date,
                    'source_type' => $draft->sourceType,
                    'source_id' => $draft->sourceId,
                    'total' => $draft->totalDebit()->toDecimalString(),
                    'currency' => $draft->baseCurrency,
                ], JSON_THROW_ON_ERROR),
            ]);

            DB::table('audit_log')->insert([
                'company_id' => $draft->companyId,
                'user_id' => $userId,
                'actor_type' => $userId ? 'user' : 'system',
                'action' => 'posted',
                'subject_type' => 'journal_entry',
                'subject_id' => $entryId,
                'after' => json_encode(['number' => $number, 'memo' => $draft->memo], JSON_THROW_ON_ERROR),
            ]);

            return JournalEntry::query()->with('lines')->where('company_id', $draft->companyId)->findOrFail($entryId);
        });
    }

    /** Reverse a posted entry with a mirror entry; the original is marked 'reversed', never edited. */
    public function reverse(string $entryId, \DateTimeImmutable $date, ?string $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($entryId, $date, $userId) {
            $entry = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($entryId);

            if ($entry->status !== 'posted') {
                throw new LedgerException("Journal entry {$entry->number} is already reversed.");
            }
            // A reconciled entry is part of a statement the books agreed with (028): undo the reconciliation first.
            if (DB::table('reconciliation_lines as rl')
                ->join('reconciliations as r', fn ($j) => $j->on('r.company_id', '=', 'rl.company_id')->on('r.id', '=', 'rl.reconciliation_id'))
                ->where(['rl.company_id' => $entry->company_id, 'r.status' => 'completed'])->whereIn('rl.journal_line_id', $entry->lines->pluck('id')->all())->exists()) {
                throw new LedgerException("Journal entry {$entry->number} is in a completed bank reconciliation: undo the reconciliation first.");
            }
            // A bank statement line explained by this entry would be left pointing at money that never moved (027).
            if (DB::table('bank_transactions')->where('company_id', $entry->company_id)->whereIn('journal_line_id', $entry->lines->pluck('id')->all())->exists()) {
                throw new LedgerException("Journal entry {$entry->number} is matched to a bank statement line: undo the match on the statement first.");
            }

            $baseCurrency = DB::table('companies')->where('id', $entry->company_id)->value('base_currency');
            $reversal = new JournalDraft(
                $entry->company_id, $date, $baseCurrency, $entry->currency, (string) $entry->fx_rate,
                "Reversal of #{$entry->number}" . ($entry->memo ? ": {$entry->memo}" : ''), 'reversal', $entry->id,
            );

            // Swap every side; amount_txn flips sign with it.
            foreach ($entry->lines as $line) {
                $debit = Money::of($line->debit, $baseCurrency);
                $txn = Money::of($line->amount_txn, $entry->currency)->negated();
                $dimensions = array_filter(['location_id' => $line->location_id, 'class_id' => $line->class_id, 'project_id' => $line->project_id]);

                $reversal->add($debit->isZero()
                    ? JournalLineDraft::debit($line->account_id, Money::of($line->credit, $baseCurrency), $txn, $line->description, $line->contact_id, $dimensions)
                    : JournalLineDraft::credit($line->account_id, $debit, $txn->negated(), $line->description, $line->contact_id, $dimensions));
            }

            $posted = $this->post($reversal, $userId);
            DB::table('journal_entries')->where(['company_id' => $entry->company_id, 'id' => $entry->id])->update(['status' => 'reversed']);

            return $posted;
        });
    }

    private function assertAccountsUsable(JournalDraft $draft): void
    {
        $ids = array_values(array_unique(array_map(fn (JournalLineDraft $l) => $l->accountId, $draft->lines())));

        $found = DB::table('accounts')
            ->where('company_id', $draft->companyId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->count();

        if ($found !== count($ids)) {
            throw new LedgerException('One or more accounts are inactive or belong to another company.');
        }
    }

    public function nextNumber(string $companyId, string $key): int
    {
        DB::statement(
            'insert into company_sequences (company_id, key, next_value) values (?, ?, 1) on conflict do nothing',
            [$companyId, $key],
        );

        $row = DB::table('company_sequences')
            ->where(['company_id' => $companyId, 'key' => $key])
            ->lockForUpdate()
            ->first(['next_value']);

        DB::table('company_sequences')
            ->where(['company_id' => $companyId, 'key' => $key])
            ->update(['next_value' => $row->next_value + 1]);

        return (int) $row->next_value;
    }
}
