<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Which money account a payment may go through, as QuickBooks allows it: one in the payment's own currency, or one in
 * the company's (a dollar invoice paid into a birr bank account, a dollar bill paid from it), never a third currency
 * (107). Through the company's currency, what the account moved for the whole payment is given in that currency, and
 * the payment's rate is that amount over the payment's (the rate the bank gave).
 */
final class PaymentAccount
{
    /**
     * Null when the account is in the payment's own currency; otherwise what it moved, in the company's currency.
     */
    public static function amountMoved(Account $account, Company $company, string $currency, mixed $given, string $field = 'account_id'): ?Money
    {
        $held = $account->currency ?? $company->base_currency;
        if ($held === $currency) {
            return null;
        }
        if ($held !== $company->base_currency) {
            throw ValidationException::withMessages([$field => ["{$account->name} is kept in {$held}: a {$currency} payment goes through an account in {$currency} or in {$company->base_currency}."]]);
        }
        if (! is_string($given) || ! preg_match('/^\d{1,18}(\.\d{1,2})?$/', $given) || BigDecimal::of($given)->isZero()) {
            throw ValidationException::withMessages(['account_amount' => ["Enter what {$account->name} moved for the {$currency} payment, in {$held}."]]);
        }

        return Money::of($given, $held);
    }

    /** The rate the bank gave: what the account moved over the payment's amount, to 6 decimals. */
    public static function rate(Money $moved, Money $amount): string
    {
        return (string) $moved->amount->dividedBy($amount->amount, 6, Rounding::halfUp());
    }
}
