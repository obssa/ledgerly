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
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    /**
     * AR ageing today: open invoices by days past their due date (the issue date when there is none), credit not yet
     * used (credit notes left, payments not allocated) shown apart, and the total, which equals the customer's
     * balance on the receivable account.
     *
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function ageing(string $companyId, string $receivableAccountId, string $currency, string $asOf, string $documentType = 'invoice'): array
    {
        $payable = $documentType === 'bill';
        $open = DB::select(<<<'SQL'
            SELECT d.contact_id, d.id, d.number, d.reference, d.issue_date::text AS issue_date, d.due_date::text AS due_date,
                   d.currency, d.amount_due::text AS amount_due, d.total::text AS total,
                   greatest(?::date - coalesce(d.due_date, d.issue_date), 0) AS days,
                   round(d.amount_due * d.fx_rate, 2)::text AS amount
              FROM documents d
             WHERE d.company_id = ?::uuid AND d.type = ?
               AND d.status IN ('posted', 'part_paid') AND d.amount_due > 0
             ORDER BY coalesce(d.due_date, d.issue_date), d.number
            SQL, [$asOf, $companyId, $payable ? 'bill' : 'invoice']);

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
            $rows[$o->contact_id] ??= ['buckets' => array_fill_keys(self::BUCKETS, $zero())];
            $amount = Money::of($o->amount, $currency);
            $bucket = match (true) {
                $o->days <= 0 => 'current',
                $o->days <= 30 => 'days_1_30',
                $o->days <= 60 => 'days_31_60',
                $o->days <= 90 => 'days_61_90',
                default => 'over_90',
            };
            $rows[$o->contact_id]['buckets'][$bucket] = $rows[$o->contact_id]['buckets'][$bucket]->plus($amount);
            // The detail: each open document, in the bucket its days late put it in.
            $documents[] = ['id' => $o->id, 'contact_id' => $o->contact_id, 'number' => $o->number, 'reference' => $o->reference,
                'issue_date' => $o->issue_date, 'due_date' => $o->due_date, 'days_late' => (int) $o->days, 'bucket' => $bucket,
                'currency' => $o->currency, 'total' => $o->total, 'amount_due' => $o->amount_due, 'amount' => $amount->toDecimalString()];
        }
        foreach ($ledger as $contactId => $balance) {
            $rows[$contactId] ??= ['buckets' => array_fill_keys(self::BUCKETS, $zero())];
        }

        $names = DB::table('contacts')->whereIn('id', array_keys($rows))->pluck('name', 'id');
        $out = [];
        $totals = array_fill_keys([...self::BUCKETS, 'credits', 'total'], $zero());
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
            foreach (self::BUCKETS as $b) {
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

        return ['as_of' => $asOf, 'currency' => $currency, 'rows' => $out, 'totals' => array_map(fn (Money $m) => $m->toDecimalString(), $totals), 'documents' => $documents];
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
            SELECT l.entry_date::text AS date, e.number AS entry, e.source_type, e.memo,
                   coalesce(d.number, p.reference) AS reference, d.id AS document_id, l.debit::text AS debit, l.credit::text AS credit
              FROM journal_lines l
              JOIN journal_entries e ON e.company_id = l.company_id AND e.id = l.journal_entry_id
              LEFT JOIN documents d ON d.id = e.source_id AND e.source_type IN ('invoice', 'credit_note', 'bill')
              LEFT JOIN payments p ON p.id = e.source_id AND e.source_type = 'payment'
             WHERE l.company_id = ?::uuid AND l.account_id = ?::uuid AND l.contact_id = ?::uuid
               AND l.entry_date BETWEEN ?::date AND ?::date
             ORDER BY l.entry_date, e.number, l.line_no
            SQL, [$companyId, $receivableAccountId, $contactId, $from, $to]);

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
