<?php

/*
| Role => permissions. "module.*" grants every action in a module; "*" grants everything.
| The 'custom' role starts empty and gets grants stored on the membership (Advanced plan).
*/

return [
    'roles' => [
        'owner' => ['*'],
        'admin' => ['*'],
        'accountant' => ['accounting.*', 'journals.*', 'invoices.*', 'bills.*', 'banking.*', 'reports.*', 'tax.*', 'contacts.*'],
        'bookkeeper' => ['accounting.view', 'invoices.view', 'invoices.create', 'bills.view', 'bills.create', 'banking.*', 'contacts.*', 'reports.view'],
        'sales' => ['invoices.view', 'invoices.create', 'contacts.view', 'contacts.create', 'payments.receive'],
        'purchasing' => ['bills.view', 'bills.create', 'contacts.view', 'contacts.create'],
        'payroll' => ['payroll.*'],
        'approver' => ['invoices.view', 'invoices.approve', 'bills.view', 'bills.approve', 'payments.approve'],
        'viewer' => ['accounting.view', 'invoices.view', 'bills.view', 'reports.view'],
        'custom' => [],
    ],
];
