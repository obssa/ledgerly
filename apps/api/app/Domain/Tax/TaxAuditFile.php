<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use Illuminate\Support\Facades\DB;

/**
 * The tax audit file (Module 7): every sales and purchase document of a period, and every ledger line, as CSV for the
 * tax authority or an auditor. Text a spreadsheet would read as a formula (=, +, -, @ at the start) is prefixed with
 * an apostrophe, so opening the file never runs anything.
 */
final class TaxAuditFile
{
    public const MAX_ROWS = 50000;

    private const DOCUMENT_TYPES = ['invoice', 'credit_note', 'sales_receipt', 'bill', 'vendor_credit', 'expense'];

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function documents(string $companyId, string $from, string $to): array
    {
        $rows = DB::table('documents as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->leftJoin('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'd.company_id')->on('e.id', '=', 'd.journal_entry_id'))
            ->where('d.company_id', $companyId)->whereIn('d.type', self::DOCUMENT_TYPES)->whereNotIn('d.status', ['draft', 'awaiting_approval'])
            ->whereBetween('d.issue_date', [$from, $to])->orderBy('d.issue_date')->orderBy('d.type')->orderBy('d.number')
            ->limit(self::MAX_ROWS + 1)
            ->get(['d.type', 'd.number', 'd.issue_date', 'd.status', 'c.name as contact', 'c.tax_id', 'd.currency', 'd.fx_rate', 'd.subtotal', 'd.tax_total', 'd.total',
                'e.number as entry']);

        return $this->csv(['type', 'number', 'date', 'status', 'customer_or_supplier', 'tin', 'currency', 'fx_rate', 'subtotal', 'tax', 'total', 'journal_entry'],
            $rows->map(fn ($d) => [$d->type, $d->number, $d->issue_date, $d->status, $d->contact, $d->tax_id, $d->currency, (string) $d->fx_rate,
                (string) $d->subtotal, (string) $d->tax_total, (string) $d->total, $d->entry]));
    }

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function ledger(string $companyId, string $from, string $to): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('e.number')->orderBy('l.line_no')->limit(self::MAX_ROWS + 1)
            ->get(['e.number', 'l.entry_date', 'e.source_type', 'e.memo', 'a.code', 'a.name', 'l.description', 'c.name as contact', 'l.debit', 'l.credit', 'e.status']);

        return $this->csv(['entry', 'date', 'source', 'memo', 'account_code', 'account', 'description', 'contact', 'debit', 'credit', 'entry_status'],
            $rows->map(fn ($l) => [$l->number, $l->entry_date, $l->source_type, $l->memo, $l->code, $l->name, $l->description, $l->contact,
                (string) $l->debit, (string) $l->credit, $l->status]));
    }

    /**
     * @param list<string> $header
     * @param \Illuminate\Support\Collection<int, list<mixed>> $rows
     * @return array{csv: string, rows: int, truncated: bool}
     */
    private function csv(array $header, \Illuminate\Support\Collection $rows): array
    {
        $truncated = $rows->count() > self::MAX_ROWS;
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows->take(self::MAX_ROWS) as $row) {
            fputcsv($out, array_map(fn ($v) => self::safe($v), $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return ['csv' => $csv, 'rows' => min($rows->count(), self::MAX_ROWS), 'truncated' => $truncated];
    }

    /** A cell a spreadsheet would read as a formula gets an apostrophe in front; numbers are written as they are. */
    public static function safe(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return $text;
        }

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }
}
