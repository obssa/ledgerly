<?php

declare(strict_types=1);

namespace App\Domain\Export;

use Illuminate\Support\Facades\DB;

/**
 * Module 11: everything a company keeps in Evenbooks, as one CSV per table in a ZIP, so the books can be taken
 * elsewhere (SECURITY.md S15). Read as the application inside the company's context, so row-level security
 * still decides what is in it, and every query also names the company.
 *
 * The tables are every company table the database knows (so a new module's table is never forgotten), less the
 * ones that are Evenbooks' own machinery or hold link hashes. Table and column names come from the database
 * catalog, never from a request.
 */
final class CompanyExport
{
    /** Not the company's records: internal queues, retry keys, number counters, link hashes, per-user settings. */
    public const LEFT_OUT = ['invitation_tokens', 'idempotency_keys', 'outbox_events', 'demo_accounts', 'saved_reports', 'company_sequences'];

    /**
     * Writes the archive to $out.
     *
     * @param resource $out
     * @return array<string, int> rows per file
     */
    public function write(string $companyId, $out): array
    {
        $zip = new ZipWriter($out);
        $counts = [];

        $counts['company.csv'] = $this->addCsv($zip, 'company.csv', DB::table('companies')->where('id', $companyId)->orderBy('id')->cursor());
        $counts['people.csv'] = $this->addCsv($zip, 'people.csv', DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $companyId)
            ->orderBy('m.created_at')->orderBy('m.id')
            ->cursor(['m.id', 'u.name', 'u.email', 'm.role', 'm.status', 'm.invited_at', 'm.created_at']));

        foreach ($this->tables() as $table => ['order' => $order, 'columns' => $columns]) {
            if ($table === 'memberships') {
                continue; // in people.csv, with names and emails
            }
            $query = DB::table($table)->where('company_id', $companyId);
            foreach ($order as $column) {
                $query->orderBy($column);
            }
            $counts["{$table}.csv"] = $this->addCsv($zip, "{$table}.csv", $query->cursor(), $columns);
        }

        $zip->add('README.txt', $this->readme($counts));
        $zip->finish();

        return $counts;
    }

    /** @return array<string, array{order: list<string>, columns: list<string>}> company table => its primary key (to keep the rows in order) and its columns */
    public function tables(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.relname AS name,
                   coalesce((SELECT string_agg(a.attname, ',' ORDER BY k.ord)
                               FROM pg_index i
                               CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
                               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
                              WHERE i.indrelid = c.oid AND i.indisprimary), '') AS pk,
                   (SELECT string_agg(a.attname, ',' ORDER BY a.attnum) FROM pg_attribute a
                     WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped) AS columns
              FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND c.relrowsecurity
               -- Tables closed to the application (billing events, the staff console's own) are not the company's records;
               -- TenantIsolationTest names each one, so none is left out by accident.
               AND has_table_privilege(c.oid, 'SELECT')
               AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'company_id' AND NOT a.attisdropped)
             ORDER BY c.relname
            SQL);

        $tables = [];
        foreach ($rows as $row) {
            if (! in_array($row->name, self::LEFT_OUT, true)) {
                $tables[$row->name] = ['order' => $row->pk === '' ? [] : explode(',', $row->pk), 'columns' => explode(',', $row->columns)];
            }
        }

        return $tables;
    }

    /**
     * @param iterable<object> $rows
     * @param list<string> $columns the heading when there are no rows
     */
    private function addCsv(ZipWriter $zip, string $name, iterable $rows, array $columns = []): int
    {
        $csv = '';
        $count = 0;
        $header = null;
        foreach ($rows as $row) {
            $row = (array) $row;
            if ($header === null) {
                $header = array_keys($row);
                $csv .= self::line($header);
            }
            $csv .= self::line(array_values($row));
            $count++;
        }
        if ($header === null) {
            $csv = $columns === [] ? '' : self::line($columns);   // an empty table: still a file with its heading
        }
        $zip->add($name, "\u{FEFF}" . $csv);   // so spreadsheets read the file as UTF-8 (Amharic names)

        return $count;
    }

    /** @param list<mixed> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(fn ($cell) => self::quote(self::cell($cell)), $cells)) . "\r\n";
    }

    /** As the report CSVs (apps/web/src/lib/csv.ts): a cell a spreadsheet would run as a formula gets an apostrophe. */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $text = (string) $value;
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'{$text}" : $text;
    }

    private static function quote(string $text): string
    {
        return preg_match('/[",\r\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
    }

    /** @param array<string, int> $counts */
    private function readme(array $counts): string
    {
        $lines = [
            'Evenbooks company data export',
            'Made ' . now()->toIso8601String(),
            '',
            'One CSV file per table (UTF-8, comma separated, first line the column names). Amounts are exact decimals;',
            'a text cell that starts with = + - or @ has an apostrophe in front so a spreadsheet does not run it.',
            'people.csv lists everyone with access to the company and their role.',
            '',
            'Rows per file:',
        ];
        foreach ($counts as $file => $n) {
            $lines[] = "  {$file}: {$n}";
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
