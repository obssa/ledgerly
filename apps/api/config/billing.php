<?php

/*
| Ledgerly's own subscriptions (053), without a payment provider: customers pay by bank transfer and the operator records
| it (`php artisan ledgerly:subscription`, as the owner role: .github/workflows/billing.yml). Prices and plan limits are
| the business's to set; nothing here charges anyone.
|
| enforce       when true, a daily job suspends trials past their end and moves unpaid subscriptions to past due, then
|               to suspended after the grace days (suspended books are read-only). Off until billing starts.
| grace_days    days past the paid-until date before a past-due subscription is suspended
| instructions  how to pay, shown to owners and admins (bank, account, reference to quote)
| contact       where to ask about billing
*/

return [
    'enforce' => filter_var(env('BILLING_ENFORCE', false), FILTER_VALIDATE_BOOL),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 14),
    'instructions' => env('BILLING_PAYMENT_INSTRUCTIONS', ''),
    'contact' => env('BILLING_CONTACT_EMAIL', ''),
    'plans' => [
        'starter' => 'Starter',
        'essentials' => 'Essentials',
        'plus' => 'Plus',
        'advanced' => 'Advanced',
        'accountant' => 'Accountant',
    ],
];
