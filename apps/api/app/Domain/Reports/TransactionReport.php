<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Module 10: the transaction reports, as QuickBooks lists them (Transaction list by date, Invoice list, Open invoices,
 * Bill list, Unpaid bills, Payments received and made). Every row has the same fields, so the reader picks the columns
 * (Customize, Columns): date, transaction type, number, name, memo, due date, days past due, status, amount, open balance,
 * tax, amount before tax, foreign amount, foreign open balance, currency, exchange rate, location, account, method,
 * applied to, created by, create date, posted by. Amounts are in the company's currency; the foreign ones only for a
 * transaction in another currency. The SQL is fixed: what the request chooses arrives as bindings or as one of these
 * keys, never as SQL (CLAUDE.md, security rule 1).
 */
final class TransactionReport
{
    /** Each report: where its rows come from, which document types, which statuses. */
    public const REPORTS = [
        'transactions' => ['source' => 'ledger'],
        'invoices' => ['source' => 'documents', 'types' => ['invoice']],
        'open_invoices' => ['source' => 'documents', 'types' => ['invoice', 'credit_note'], 'open' => true],
        'bills' => ['source' => 'documents', 'types' => ['bill']],
        'unpaid_bills' => ['source' => 'documents', 'types' => ['bill', 'vendor_credit'], 'open' => true],
        'payments_received' => ['source' => 'payments', 'direction' => 'in'],
        'payments_made' => ['source' => 'payments', 'direction' => 'out'],
    ];

    /** A status filter of a document list, as fixed SQL. */
    private const STATUS = [
        'open' => "d.status IN ('posted', 'part_paid') AND d.amount_due > 0",
        'overdue' => "d.status IN ('posted', 'part_paid') AND d.amount_due > 0 AND d.due_date < :today",
        'paid' => "d.status = 'paid'",
        'draft' => "d.status IN ('draft', 'awaiting_approval')",
        'void' => "d.status = 'void'",
    ];

    /** The fields every row has: the columns a saved report may keep (Customize, Columns). */
    public const COLUMNS = ['date', 'type', 'number', 'status', 'memo', 'name', 'tin', 'due_date', 'days_past_due', 'amount', 'open_balance',
        'amount_before_tax', 'tax', 'foreign_amount', 'foreign_open_balance', 'currency', 'exchange_rate', 'location', 'account', 'method',
        'applied_to', 'created_by', 'created_at', 'posted_by'];

    public const MAX_ROWS = 5000;

    /**
     * @param  array{from: string, to: string, contact_id?: ?string, status?: ?string, created_by?: ?string}  $f
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function run(string $companyId, string $base, string $today, string $report, array $f): array
    {
        $def = self::REPORTS[$report];
        $bind = ['company' => $companyId, 'base' => $base, 'from' => $f['from'], 'to' => $f['to']];
        $where = [];
        if (! empty($f['contact_id'])) {
            $where[] = 'c.id = :contact';
            $bind['contact'] = $f['contact_id'];
        }

        if ($def['source'] === 'ledger') {
            if (! empty($f['created_by'])) {
                $where[] = 'coalesce(d.created_by, je.posted_by) = :created_by';
                $bind['created_by'] = $f['created_by'];
            }
            $rows = DB::select($this->ledgerSql($where), $bind);
        } elseif ($def['source'] === 'documents') {
            $types = implode(', ', array_map(fn ($t) => "'{$t}'", $def['types']));   // fixed, from REPORTS
            $where[] = "d.type IN ({$types})";
            $status = ($def['open'] ?? false) ? ($f['status'] === 'overdue' ? 'overdue' : 'open') : ($f['status'] ?? null);
            if ($status !== null && isset(self::STATUS[$status])) {
                $where[] = self::STATUS[$status];
                if ($status === 'overdue') {
                    $bind['today'] = $today;
                }
            } elseif (! ($def['open'] ?? false)) {
                $where[] = "d.status <> 'void'";
            }
            if (! empty($f['created_by'])) {
                $where[] = 'd.created_by = :created_by';
                $bind['created_by'] = $f['created_by'];
            }
            $rows = DB::select($this->documentSql($where), $bind);
        } else {
            $where[] = 'p.direction = :direction';
            $bind['direction'] = $def['direction'];
            if (! empty($f['created_by'])) {
                $where[] = 'je.posted_by = :created_by';
                $bind['created_by'] = $f['created_by'];
            }
            $rows = DB::select($this->paymentSql($where), $bind);
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $r) {
            $row = (array) $r;
            $open = $row['open_balance'] ?? null;
            $row['days_past_due'] = $row['due_date'] && $open !== null && (float) $open > 0 && $row['due_date'] < $today
                ? (int) (new \DateTimeImmutable($row['due_date']))->diff(new \DateTimeImmutable($today))->days : null;
            $out[] = $row;
        }

        return ['rows' => $out, 'truncated' => $truncated];
    }

    private const FIELDS = 'id, date, type, number, name, tin, memo, due_date, status, amount, open_balance, tax, amount_before_tax,
        foreign_amount, foreign_open_balance, currency, exchange_rate, location, account, method, applied_to, created_by, created_at, posted_by';

    /** Every posted entry of the books in the period, with its invoice, bill or payment when it has one. */
    private function ledgerSql(array $where): string
    {
        $and = $where === [] ? '' : ' AND ' . implode(' AND ', $where);

        return "SELECT " . self::FIELDS . " FROM (
            SELECT je.id::text AS id, je.entry_date::text AS date,
                coalesce(d.type, CASE p.direction WHEN 'in' THEN 'payment_received' WHEN 'out' THEN 'payment_made' END, je.source_type) AS type,
                coalesce(d.number, p.reference, je.number::text) AS number,
                c.name, c.tax_id AS tin, coalesce(je.memo, d.notes) AS memo, d.due_date::text AS due_date,
                coalesce(d.status, p.status, je.status) AS status,
                t.base::numeric(20,2)::text AS amount,
                CASE WHEN d.journal_entry_id = je.id THEN round(d.amount_due * d.fx_rate, 2)::text END AS open_balance,
                CASE WHEN d.journal_entry_id = je.id THEN round(d.tax_total * d.fx_rate, 2)::text END AS tax,
                CASE WHEN d.journal_entry_id = je.id THEN round(d.subtotal * d.fx_rate, 2)::text END AS amount_before_tax,
                CASE WHEN je.currency <> k.base THEN t.txn::numeric(20,2)::text END AS foreign_amount,
                CASE WHEN d.journal_entry_id = je.id AND d.currency <> k.base THEN d.amount_due::text END AS foreign_open_balance,
                CASE WHEN je.currency <> k.base THEN je.currency END AS currency,
                CASE WHEN je.currency <> k.base THEN je.fx_rate::text END AS exchange_rate,
                loc.name AS location, ba.name AS account, p.method, NULL::text AS applied_to,
                cu.name AS created_by, coalesce(d.created_at, p.created_at, je.posted_at)::text AS created_at, pu.name AS posted_by,
                je.number AS sort_number
            FROM journal_entries je
            CROSS JOIN (SELECT CAST(:base AS char(3)) AS base) k
            JOIN LATERAL (SELECT sum(l.debit) AS base, sum(l.amount_txn) FILTER (WHERE l.amount_txn > 0) AS txn,
                    (array_agg(l.contact_id) FILTER (WHERE l.contact_id IS NOT NULL))[1] AS contact_id
                  FROM journal_lines l WHERE l.company_id = je.company_id AND l.journal_entry_id = je.id) t ON true
            LEFT JOIN documents d ON d.company_id = je.company_id AND je.source_id = d.id
                AND je.source_type IN ('invoice', 'credit_note', 'bill', 'vendor_credit', 'sales_receipt')
            LEFT JOIN payments p ON p.company_id = je.company_id AND p.journal_entry_id = je.id
            LEFT JOIN contacts c ON c.company_id = je.company_id AND c.id = coalesce(d.contact_id, p.contact_id, t.contact_id)
            LEFT JOIN users cu ON cu.id = coalesce(d.created_by, je.posted_by)
            LEFT JOIN users pu ON pu.id = je.posted_by
            LEFT JOIN dimensions loc ON loc.company_id = je.company_id AND loc.id = d.location_id
            LEFT JOIN accounts ba ON ba.company_id = je.company_id AND ba.id = p.account_id
            WHERE je.company_id = :company AND je.entry_date BETWEEN :from AND :to{$and}
        ) x ORDER BY date, sort_number LIMIT " . (self::MAX_ROWS + 1);
    }

    /** Invoices, bills and credits, with what is still owed on each. */
    private function documentSql(array $where): string
    {
        return "SELECT " . self::FIELDS . " FROM (
            SELECT d.id::text AS id, d.issue_date::text AS date, d.type, d.number, c.name, c.tax_id AS tin, d.notes AS memo,
                d.due_date::text AS due_date, d.status,
                round(d.total * d.fx_rate, 2)::text AS amount, round(d.amount_due * d.fx_rate, 2)::text AS open_balance,
                round(d.tax_total * d.fx_rate, 2)::text AS tax, round(d.subtotal * d.fx_rate, 2)::text AS amount_before_tax,
                CASE WHEN d.currency <> k.base THEN d.total::text END AS foreign_amount,
                CASE WHEN d.currency <> k.base THEN d.amount_due::text END AS foreign_open_balance,
                CASE WHEN d.currency <> k.base THEN d.currency END AS currency,
                CASE WHEN d.currency <> k.base THEN d.fx_rate::text END AS exchange_rate,
                loc.name AS location, NULL::text AS account, NULL::text AS method, NULL::text AS applied_to,
                cu.name AS created_by, d.created_at::text AS created_at, pu.name AS posted_by
            FROM documents d
            CROSS JOIN (SELECT CAST(:base AS char(3)) AS base) k
            JOIN contacts c ON c.company_id = d.company_id AND c.id = d.contact_id
            LEFT JOIN journal_entries je ON je.company_id = d.company_id AND je.id = d.journal_entry_id
            LEFT JOIN users cu ON cu.id = d.created_by
            LEFT JOIN users pu ON pu.id = je.posted_by
            LEFT JOIN dimensions loc ON loc.company_id = d.company_id AND loc.id = d.location_id
            WHERE d.company_id = :company AND d.issue_date BETWEEN :from AND :to AND " . implode(' AND ', $where) . "
        ) x ORDER BY date, number LIMIT " . (self::MAX_ROWS + 1);
    }

    /** Payments received or made, with the documents each was applied to. */
    private function paymentSql(array $where): string
    {
        return "SELECT " . self::FIELDS . " FROM (
            SELECT p.id::text AS id, p.payment_date::text AS date,
                CASE p.direction WHEN 'in' THEN 'payment_received' ELSE 'payment_made' END AS type,
                p.reference AS number, c.name, c.tax_id AS tin, NULL::text AS memo, NULL::text AS due_date, p.status,
                round(p.amount * p.fx_rate, 2)::text AS amount, NULL::text AS open_balance, NULL::text AS tax, NULL::text AS amount_before_tax,
                CASE WHEN p.currency <> k.base THEN p.amount::text END AS foreign_amount, NULL::text AS foreign_open_balance,
                CASE WHEN p.currency <> k.base THEN p.currency END AS currency,
                CASE WHEN p.currency <> k.base THEN p.fx_rate::text END AS exchange_rate,
                NULL::text AS location, ba.name AS account, p.method,
                (SELECT string_agg(ad.number, ', ' ORDER BY ad.number) FROM payment_allocations a
                   JOIN documents ad ON ad.company_id = a.company_id AND ad.id = a.document_id
                  WHERE a.company_id = p.company_id AND a.payment_id = p.id) AS applied_to,
                pu.name AS created_by, p.created_at::text AS created_at, pu.name AS posted_by
            FROM payments p
            CROSS JOIN (SELECT CAST(:base AS char(3)) AS base) k
            LEFT JOIN contacts c ON c.company_id = p.company_id AND c.id = p.contact_id
            LEFT JOIN journal_entries je ON je.company_id = p.company_id AND je.id = p.journal_entry_id
            LEFT JOIN users pu ON pu.id = je.posted_by
            LEFT JOIN accounts ba ON ba.company_id = p.company_id AND ba.id = p.account_id
            WHERE p.company_id = :company AND p.payment_date BETWEEN :from AND :to AND " . implode(' AND ', $where) . "
        ) x ORDER BY date, number LIMIT " . (self::MAX_ROWS + 1);
    }
}
