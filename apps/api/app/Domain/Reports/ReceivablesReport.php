<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Ledger\Money;
use Illuminate\Support\Facades\DB;

/**
 * Module 2 and 3 reports on what customers owe and what the company owes suppliers, in the base currency. Payables
 * read the same way with the sign turned: a bill raises the balance (a credit), a payment lowers it. Every query takes its values as bound parameters;
 * nothing from a request becomes SQL (CLAUDE.md rule 1).
 *
 * Both reports agree with the ledger by construction: the ageing's total per customer is that customer's balance on
 * the receivable account, and the statement is the receivable account's lines for the customer.
 */
final class ReceivablesReport
{
    /** The standard ageing periods (30 days each, four of them): Current, 1-30, 31-60, 61-90, Over 90. */
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    /** The fields of each open document in the detail (Customize, Columns); also the list a saved report may keep. */
    public const DETAIL_COLUMNS = ['date', 'type', 'number', 'reference', 'memo', 'name', 'due_date', 'days_past_due', 'age', 'amount', 'open_balance',
        'foreign_amount', 'foreign_open_balance', 'currency', 'exchange_rate', 'location', 'created_by', 'posted_by'];

    /**
     * The ageing periods, as QuickBooks sets them: "days per ageing period" and "number of periods". Current, then
     * periods - 1 runs of that many days, then everything older: 30 and 4 give Current, 1-30, 31-60, 61-90, Over 90.
     *
     * @return list<string>
     */
    public static function buckets(int $interval = 30, int $periods = 4): array
    {
        $keys = ['current'];
        for ($i = 1; $i < $periods; $i++) {
            $keys[] = 'days_' . (($i - 1) * $interval + 1) . '_' . ($i * $interval);
        }
        $keys[] = 'over_' . (($periods - 1) * $interval);

        return $keys;
    }

    /**
     * AR ageing at a date: open invoices by days past their due date (the issue date when there is none), credit not
     * yet used (credit notes left, payments not allocated) shown apart, and the total, which equals the customer's
     * balance on the receivable account. A document issued after the date is not counted: it was not owed yet.
     *
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function ageing(string $companyId, string $receivableAccountId, string $currency, string $asOf, string $documentType = 'invoice', int $interval = 30, int $periods = 4): array
    {
        $payable = $documentType === 'bill';
        $buckets = self::buckets($interval, $periods);
        $open = DB::select(<<<'SQL'
            SELECT d.contact_id, d.id, d.type, d.number, d.reference, d.notes, d.issue_date::text AS issue_date, d.due_date::text AS due_date,
                   d.currency, d.fx_rate::text AS fx_rate, d.amount_due::text AS amount_due, d.total::text AS total,
                   greatest(?::date - coalesce(d.due_date, d.issue_date), 0) AS days,
                   round(d.amount_due * d.fx_rate, 2)::text AS amount, round(d.total * d.fx_rate, 2)::text AS base_total,
                   cu.name AS created_by, pu.name AS posted_by, loc.name AS location
              FROM documents d
              LEFT JOIN journal_entries je ON je.company_id = d.company_id AND je.id = d.journal_entry_id
              LEFT JOIN users cu ON cu.id = d.created_by
              LEFT JOIN users pu ON pu.id = je.posted_by
              LEFT JOIN dimensions loc ON loc.company_id = d.company_id AND loc.id = d.location_id
             WHERE d.company_id = ?::uuid AND d.type = ?
               AND d.status IN ('posted', 'part_paid') AND d.amount_due > 0 AND d.issue_date <= ?::date
             ORDER BY coalesce(d.due_date, d.issue_date), d.number
            SQL, [$asOf, $companyId, $payable ? 'bill' : 'invoice', $asOf]);

        $ledger = collect(DB::select(<<<'SQL'
            SELECT l.contact_id, sum(l.debit - l.credit)::text AS balance
              FROM journal_lines l
             WHERE l.company_id = ?::uuid AND l.account_id = ?::uuid AND l.contact_id IS NOT NULL AND l.entry_date <= ?::date
             GROUP BY l.contact_id
            SQL, [$companyId, $receivableAccountId, $asOf]))->pluck('balance', 'contact_id');

        $zero = fn () => Money::zero($currency);
        $rows = [];
        $documents = [];
        foreach ($open as $o) {
            $rows[$o->contact_id] ??= ['buckets' => array_fill_keys($buckets, $zero())];
            $amount = Money::of($o->amount, $currency);
            // 1 to interval days late is the first period after Current; the last one takes everything older.
            $bucket = $o->days <= 0 ? 'current' : $buckets[min(intdiv((int) $o->days - 1, $interval) + 1, $periods)];
            $rows[$o->contact_id]['buckets'][$bucket] = $rows[$o->contact_id]['buckets'][$bucket]->plus($amount);
            // The detail: each open document, in the bucket its days late put it in.
            $documents[] = ['id' => $o->id, 'contact_id' => $o->contact_id, 'number' => $o->number, 'reference' => $o->reference,
                'issue_date' => $o->issue_date, 'due_date' => $o->due_date, 'days_late' => (int) $o->days, 'bucket' => $bucket,
                'currency' => $o->currency, 'total' => $o->total, 'amount_due' => $o->amount_due, 'amount' => $amount->toDecimalString(),
                'type' => $o->type, 'memo' => $o->notes, 'base_total' => $o->base_total, 'exchange_rate' => $o->fx_rate,
                'created_by' => $o->created_by, 'posted_by' => $o->posted_by, 'location' => $o->location];
        }
        foreach ($ledger as $contactId => $balance) {
            $rows[$contactId] ??= ['buckets' => array_fill_keys($buckets, $zero())];
        }

        $names = DB::table('contacts')->whereIn('id', array_keys($rows))->pluck('name', 'id');
        $out = [];
        $totals = array_fill_keys([...$buckets, 'credits', 'total'], $zero());
        foreach ($rows as $contactId => $r) {
            $total = Money::of((string) ($ledger[$contactId] ?? '0'), $currency);
            if ($payable) {
                $total = $total->negated(); // what is owed to the supplier is a credit balance
            }
            $openSum = array_reduce($r['buckets'], fn (Money $s, Money $m) => $s->plus($m), $zero());
            // Credit not yet used (credit notes left, payments not allocated) is the ledger balance less the open invoices.
            $credits = $total->minus($openSum);
            if ($openSum->isZero() && $total->isZero()) {
                continue;
            }
            $row = ['contact_id' => $contactId, 'name' => $names[$contactId] ?? ''];
            foreach ($buckets as $b) {
                $row[$b] = $r['buckets'][$b]->toDecimalString();
                $totals[$b] = $totals[$b]->plus($r['buckets'][$b]);
            }
            $row['credits'] = $credits->toDecimalString();
            $row['total'] = $total->toDecimalString();
            $totals['credits'] = $totals['credits']->plus($credits);
            $totals['total'] = $totals['total']->plus($total);
            $out[] = $row;
        }
        usort($out, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ['as_of' => $asOf, 'currency' => $currency, 'interval' => $interval, 'periods' => $periods, 'buckets' => $buckets, 'rows' => $out, 'totals' => array_map(fn (Money $m) => $m->toDecimalString(), $totals), 'documents' => $documents];
    }

    /**
     * A customer's statement: the receivable balance before the period, every line in it (invoices, payments,
     * credit notes, reversals) with a running balance, and the balance at the end.
     *
     * @return array{from: string, to: string, opening: string, lines: list<array<string, mixed>>, closing: string}
     */
    public function statement(string $companyId, string $receivableAccountId, string $contactId, string $currency, string $from, string $to, bool $payable = false): array
    {
        $opening = (string) (DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(debit - credit), 0)::text AS balance FROM journal_lines
             WHERE company_id = ?::uuid AND account_id = ?::uuid AND contact_id = ?::uuid AND entry_date < ?::date
            SQL, [$companyId, $receivableAccountId, $contactId, $from])->balance);

        $rows = DB::select(<<<'SQL'
            SELECT l.entry_date::text AS date, e.id AS entry_id, e.number AS entry, e.source_type, e.memo,
                   coalesce(d.number, p.reference) AS reference, d.id AS document_id, p.id AS payment_id, l.debit::text AS debit, l.credit::text AS credit,
                   coalesce(d.type, CASE p.direction WHEN 'in' THEN 'payment_received' WHEN 'out' THEN 'payment_made' END, e.source_type) AS type,
                   d.due_date::text AS due_date,
                   CASE WHEN d.journal_entry_id = e.id THEN round(d.amount_due * d.fx_rate, 2)::text END AS open_balance,
                   CASE WHEN e.currency <> ? THEN l.amount_txn::text END AS foreign_amount,
                   CASE WHEN e.currency <> ? THEN e.currency END AS currency,
                   CASE WHEN e.currency <> ? THEN e.fx_rate::text END AS exchange_rate,
                   cu.name AS created_by, pu.name AS posted_by
              FROM journal_lines l
              JOIN journal_entries e ON e.company_id = l.company_id AND e.id = l.journal_entry_id
              LEFT JOIN documents d ON d.company_id = l.company_id AND d.id = e.source_id AND e.source_type IN ('invoice', 'credit_note', 'bill', 'vendor_credit')
              LEFT JOIN payments p ON p.company_id = l.company_id AND p.id = e.source_id AND e.source_type = 'payment'
              LEFT JOIN users cu ON cu.id = coalesce(d.created_by, e.posted_by)
              LEFT JOIN users pu ON pu.id = e.posted_by
             WHERE l.company_id = ?::uuid AND l.account_id = ?::uuid AND l.contact_id = ?::uuid
               AND l.entry_date BETWEEN ?::date AND ?::date
             ORDER BY l.entry_date, e.number, l.line_no
            SQL, [$currency, $currency, $currency, $companyId, $receivableAccountId, $contactId, $from, $to]);

        $sign = fn (Money $m): Money => $payable ? $m->negated() : $m;
        $openingBalance = $sign(Money::of($opening, $currency));
        $balance = $openingBalance;
        $lines = [];
        foreach ($rows as $r) {
            $balance = $balance->plus($sign(Money::of($r->debit, $currency)->minus(Money::of($r->credit, $currency))));
            $lines[] = [...(array) $r, 'balance' => $balance->toDecimalString()];
        }

        return ['from' => $from, 'to' => $to, 'opening' => $openingBalance->toDecimalString(), 'lines' => $lines, 'closing' => $balance->toDecimalString()];
    }
}
