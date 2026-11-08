<?php

/*
| Role => permissions. "module.*" grants every action in a module; "*" grants everything.
| The 'custom' role starts empty and gets grants stored on the membership (Advanced plan).
|
| transactions.edit  edit a posted document while its period is open (history is always kept)
| periods.reopen     edit inside a closed period or fiscal year; owner and admin only (via '*')
*/

return [
    'roles' => [
        'owner' => ['*'],
        'admin' => ['*'],
        'accountant' => ['accounting.*', 'journals.*', 'transactions.edit', 'invoices.*', 'bills.*', 'banking.*', 'reports.*', 'tax.*', 'contacts.*'],
        'bookkeeper' => ['accounting.view', 'invoices.view', 'invoices.create', 'transactions.edit', 'bills.view', 'bills.create', 'banking.*', 'contacts.*', 'reports.view'],
        'sales' => ['invoices.view', 'invoices.create', 'contacts.view', 'contacts.create', 'payments.receive'],
        'purchasing' => ['bills.view', 'bills.create', 'contacts.view', 'contacts.create'],
        'payroll' => ['payroll.*'],
        'approver' => ['invoices.view', 'invoices.approve', 'bills.view', 'bills.approve', 'payments.approve'],
        'viewer' => ['accounting.view', 'invoices.view', 'bills.view', 'reports.view'],
        'custom' => [],
    ],
];
