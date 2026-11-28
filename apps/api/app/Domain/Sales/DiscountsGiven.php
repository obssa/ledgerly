<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QuickBooks' Discounts given (113): the income account a discount on an invoice or cash sale is debited to, so the
 * sales accounts keep what was sold before it and the profit and loss shows the discounts on their own line. Made the
 * first time a company gives one, like the retention account (Retention).
 */
final class DiscountsGiven
{
    public const ACCOUNT_KEY = 'discounts_given';

    public static function account(Company $company): Account
    {
        $existing = Account::query()->where('system_key', self::ACCOUNT_KEY)->first();
        if ($existing !== null) {
            return $existing;
        }
        $taken = DB::table('accounts')->where('company_id', $company->getKey())->pluck('code')->all();
        $code = collect(array_map(fn (int $n) => (string) $n, range(4900, 4999)))->first(fn (string $c) => ! in_array($c, $taken, true)) ?? 'DISC-' . Str::upper(Str::random(4));
        $account = new Account();
        $account->forceFill(['company_id' => $company->getKey(), 'code' => $code, 'name' => 'Discounts given', 'type' => 'income',
            'subtype' => 'sales', 'system_key' => self::ACCOUNT_KEY, 'is_active' => true])->save();

        return $account;
    }
}
