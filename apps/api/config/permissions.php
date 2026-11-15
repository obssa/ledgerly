<?php

/*
| Role => permissions. "module.*" grants every action in a module; "*" grants everything.
| The 'custom' role starts empty and gets grants stored on the membership (Advanced plan).
|
| transactions.edit  edit a posted document while its period is open (history is always kept)
| periods.reopen     edit or void inside a closed period or fiscal year; owner and admin only (via '*')
| invoices.approve   post an invoice; with settings.approval.invoice_above set, not your own above that amount
| contacts.edit      change or archive a customer or supplier (sales and purchasing staff only view and add)
| bills.create       enter a supplier's bill as a draft; bills.approve posts it (accountant, approver, owner, admin)
| payments.receive   record a payment received; payments.reverse reverses one (accountant, owner, admin)
| company.view       read the company profile and fiscal settings, the chart of accounts (no balances) and the tax
|                    rates; every role, because every document form names accounts and taxes
| company.manage     change the profile and settings; owner and admin only (via '*')
| periods.close      move the period lock date forward
| accounting.rates   enter or correct exchange rates (accountant via 'accounting.*')
| accounting.opening enter or replace the opening balances (accountant via 'accounting.*')
| accounting.dimensions  add, rename and turn off locations, classes and projects (accountant via 'accounting.*')
*/

return [
    'roles' => [
        'owner' => ['*'],
        'admin' => ['*'],
        'accountant' => ['company.view', 'periods.close', 'payments.*', 'accounting.*', 'journals.*', 'transactions.edit', 'invoices.*', 'bills.*', 'banking.*', 'reports.*', 'tax.*', 'contacts.*'],
        'bookkeeper' => ['company.view', 'payments.view', 'payments.receive', 'accounting.view', 'invoices.view', 'invoices.create', 'transactions.edit', 'bills.view', 'bills.create', 'banking.*', 'contacts.*', 'reports.view'],
        'sales' => ['company.view', 'payments.view', 'invoices.view', 'invoices.create', 'contacts.view', 'contacts.create', 'payments.receive'],
        'purchasing' => ['company.view', 'bills.view', 'bills.create', 'contacts.view', 'contacts.create'],
        'approver' => ['company.view', 'payments.view', 'invoices.view', 'invoices.approve', 'bills.view', 'bills.approve', 'payments.approve'],
        'viewer' => ['company.view', 'payments.view', 'accounting.view', 'invoices.view', 'bills.view', 'reports.view'],
        'custom' => [],
    ],
];
