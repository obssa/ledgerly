<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exchange rates of a company (database/schema/012_currencies.sql), as base-currency units for one unit of a foreign
 * currency. A document takes the rate of its own date (IAS 21: the spot rate at the date of the transaction) and
 * keeps it, so a later correction of the rate never changes a posted document.
 */
final class ExchangeRates
{
    /** A rate older than this is not used without asking: a week covers weekends and public holidays. */
    public const MAX_AGE_DAYS = 7;

    /**
     * The rate a document in $currency dated $date uses. The base currency is always 1. A foreign currency must be
     * one the company works in; the rate is the one given, or else the company's rate for that date.
     *
     * @throws ValidationException when the currency is not turned on, or no recent rate exists and none was given
     */
    public function forDocument(Company $company, string $currency, string $date, ?string $given): string
    {
        if ($currency === $company->base_currency) {
            return '1';
        }
        if (! $this->isEnabled($company->getKey(), $currency)) {
            throw ValidationException::withMessages(['currency' => [
                "{$currency} is not one of this company's currencies. An owner or admin can add it under Company settings.",
            ]]);
        }
        if ($given !== null && $given !== '') {
            return $given;
        }

        $rate = $this->on($company->getKey(), $currency, $date);
        if ($rate === null) {
            throw ValidationException::withMessages(['fx_rate' => [
                "There is no {$currency} rate for {$date} or the week before. Enter the rate, or add it under Currencies.",
            ]]);
        }

        return $rate['rate'];
    }

    /** @return array{rate: string, rate_date: string}|null the newest rate on or up to a week before $date */
    public function on(string $companyId, string $currency, string $date): ?array
    {
        $day = new DateTimeImmutable($date);
        $row = DB::table('exchange_rates')
            ->where(['company_id' => $companyId, 'currency' => $currency])
            ->whereBetween('rate_date', [$day->modify('-' . self::MAX_AGE_DAYS . ' days')->format('Y-m-d'), $day->format('Y-m-d')])
            ->orderByDesc('rate_date')
            ->first(['rate', 'rate_date']);

        return $row === null ? null : ['rate' => (string) $row->rate, 'rate_date' => (string) $row->rate_date];
    }

    public function isEnabled(string $companyId, string $currency): bool
    {
        return DB::table('company_currencies')->where(['company_id' => $companyId, 'currency' => $currency, 'is_enabled' => true])->exists();
    }
}
