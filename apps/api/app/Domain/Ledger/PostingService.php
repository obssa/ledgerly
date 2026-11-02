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
 * Modules (sales, purchases, payroll, banking) build a JournalDraft and hand it here.
 */
final class PostingService
{
    public function post(JournalDraft $draft, ?string $userId = null): JournalEntry
    {
        $draft->assertBalanced();

        return DB::transaction(function () use ($draft, $userId) {
            $company = DB::table('companies')->where('id', $draft->companyId)->lockForUpdate()->first(['books_closed_until']);
            $date = $draft->date->format('Y-m-d');

            if ($company?->books_closed_until !== null && $date <= $company->books_closed_until) {
                throw PeriodLocked::until($company->books_closed_until, $date);
            }

            $this->assertAccountsUsable($draft);

            $entryId = (string) Str::uuid();
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
                    'id' => (string) Str::uuid(),
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
            DB::table('journal_lines')->insert($rows);

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

            return JournalEntry::query()->with('lines')->findOrFail($entryId);
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
            DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'reversed']);

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

    private function nextNumber(string $companyId, string $key): int
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
