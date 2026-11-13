<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/** ISO 4217 currencies Ledgerly supports. Product rule: every amount is shown and stored to 2 decimal places. */
final class Currency
{
    private const DECIMALS = [
        'ETB' => 2, // Ethiopian birr
        'KES' => 2, // Kenyan shilling
        'UGX' => 2, // Ugandan shilling
        'TZS' => 2, // Tanzanian shilling
        'RWF' => 2, // Rwandan franc
        'NGN' => 2, // Nigerian naira
        'GHS' => 2, // Ghanaian cedi
        'ZAR' => 2, // South African rand
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'AED' => 2,
        'CNY' => 2,
    ];

    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! isset(self::DECIMALS[$code])) {
            throw new InvalidArgumentException("Unsupported currency: {$code}");
        }

        return $code;
    }

    public static function decimals(string $code): int
    {
        return self::DECIMALS[self::normalise($code)];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::DECIMALS);
    }
}
