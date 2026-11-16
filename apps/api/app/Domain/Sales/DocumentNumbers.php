<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Tenancy\Models\Company;
use App\Support\EthiopianCalendar;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Document numbers (database/schema/013_document_numbering.sql). Automatic numbering takes the next number when a
 * document is posted, from a counter row locked for that instant inside the posting transaction: a posting that
 * fails rolls the counter back with it, so posted numbers have no gaps. With the fiscal year in the number the
 * counter restarts each fiscal year, e.g. INV-2019-0001 (2019 is the fiscal year in the company's calendar, named
 * by the calendar year it ends in).
 */
final class DocumentNumbers
{
    /** Defaults when a company has not changed a type's numbering. */
    public const DEFAULTS = [
        'invoice' => 'INV-', 'credit_note' => 'CN-', 'sales_receipt' => 'SR-', 'quote' => 'QT-', 'sales_order' => 'SO-',
        'bill' => 'BILL-', 'vendor_credit' => 'VC-', 'purchase_order' => 'PO-', 'expense' => 'EXP-',
    ];

    /** @return array{doc_type: string, mode: string, prefix: string, include_year: bool, padding: int} */
    public function settings(string $companyId, string $type): array
    {
        $row = DB::table('document_numbering')->where(['company_id' => $companyId, 'doc_type' => $type])->first();

        return [
            'doc_type' => $type,
            'mode' => $row->mode ?? 'automatic',
            'prefix' => $row->prefix ?? self::DEFAULTS[$type],
            'include_year' => (bool) ($row->include_year ?? true),
            'padding' => (int) ($row->padding ?? 4),
        ];
    }

    public function isAutomatic(string $companyId, string $type): bool
    {
        return $this->settings($companyId, $type)['mode'] === 'automatic';
    }

    /** Takes the next number. Call only inside the transaction that posts the document. */
    public function take(Company $company, string $type, DateTimeImmutable $date): string
    {
        $s = $this->settings($company->getKey(), $type);
        $year = $s['include_year'] ? $this->fiscalYear($company, $date) : null;
        $key = 'doc:' . $type . ($year === null ? '' : ':' . $year);

        DB::insert('insert into company_sequences (company_id, key, next_value) values (?, ?, 1) on conflict do nothing', [$company->getKey(), $key]);
        $next = (int) DB::table('company_sequences')->where(['company_id' => $company->getKey(), 'key' => $key])->lockForUpdate()->value('next_value');
        DB::table('company_sequences')->where(['company_id' => $company->getKey(), 'key' => $key])->update(['next_value' => $next + 1]);

        return $this->format($s, $year, $next);
    }

    /** The number the next posted document would get, without taking it (for the settings screen). */
    public function preview(Company $company, string $type, DateTimeImmutable $date): string
    {
        $s = $this->settings($company->getKey(), $type);
        $year = $s['include_year'] ? $this->fiscalYear($company, $date) : null;
        $next = (int) (DB::table('company_sequences')->where(['company_id' => $company->getKey(), 'key' => 'doc:' . $type . ($year === null ? '' : ':' . $year)])
            ->value('next_value') ?? 1);

        return $this->format($s, $year, $next);
    }

    /** @param array{prefix: string, padding: int} $s */
    public function format(array $s, ?int $year, int $n): string
    {
        return $s['prefix'] . ($year === null ? '' : $year . '-') . str_pad((string) $n, $s['padding'], '0', STR_PAD_LEFT);
    }

    /** The fiscal year $date falls in, named by the year (in the company's calendar) in which it ends. */
    public function fiscalYear(Company $company, DateTimeImmutable $date): int
    {
        $end = (new FiscalCalendar($company->calendar, (int) $company->fiscal_year_start_month))->yearContaining($date)['end'];

        return $company->calendar === 'ethiopian' ? EthiopianCalendar::fromGregorian($end)['year'] : (int) $end->format('Y');
    }
}
