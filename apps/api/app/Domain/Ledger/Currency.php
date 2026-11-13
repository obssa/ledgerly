<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * ISO 4217 currencies. Ledgerly accepts every active currency so a company in any country can keep its
 * books and invoice in any other currency.
 *
 * Product rule: every amount is stored and shown to exactly 2 decimal places, whatever the currency.
 * Consequences to know: currencies normally written without decimals (JPY, KRW, UGX ...) show ".00", and the
 * few 3-decimal currencies (BHD, IQD, JOD, KWD, LYD, OMR, TND) are rounded to 2 decimals.
 */
final class Currency
{
    private const CODES = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD',
        'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP',
        'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD',
        'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD',
        'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
        'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS',
        'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST',
        'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    public const DECIMALS = 2;

    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Unsupported currency: {$code}");
        }

        return $code;
    }

    public static function decimals(string $code): int
    {
        self::normalise($code);

        return self::DECIMALS;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::CODES;
    }
}
