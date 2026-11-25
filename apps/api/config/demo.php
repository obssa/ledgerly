<?php

/*
| Demo sign-in: one shared account per role in a demo company, so anyone can try Evenbooks without signing up.
|
| Off unless DEMO_LOGINS=true. The deploy turns it on from the repository variable DEMO_LOGINS and then runs
| `ledgerly:demo-setup` as the owner role (database/schema/018_demo_accounts.sql). Every visitor shares these
| accounts: they cannot change a password, authenticator or email, or create a company (EnsureNotDemo).
*/

return [
    'enabled' => (bool) env('DEMO_LOGINS', false),

    // Demo sessions are short: everyone shares the accounts.
    'token_minutes' => (int) env('DEMO_TOKEN_MINUTES', 120),

    'company' => 'Abyssinia Coffee Export (demo)',

    // The roles offered on the sign-in page, in order, with what each one shows.
    'roles' => [
        'owner' => ['label' => 'Owner', 'about' => 'Everything, including company settings'],
        'accountant' => ['label' => 'Accountant', 'about' => 'Books, periods, rates, every document'],
        'bookkeeper' => ['label' => 'Bookkeeper', 'about' => 'Day-to-day entries; approval needed to post'],
        'sales' => ['label' => 'Sales', 'about' => 'Customers, draft invoices, payments received'],
        'purchasing' => ['label' => 'Purchasing', 'about' => 'Suppliers and draft bills'],
        'approver' => ['label' => 'Approver', 'about' => 'Reviews and approves invoices'],
        'viewer' => ['label' => 'Viewer', 'about' => 'Read-only, including reports'],
    ],
];
