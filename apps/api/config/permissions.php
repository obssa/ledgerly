<?php

/*
| Role => permissions. "module.*" grants every action in a module; "*" grants everything.
| The 'custom' role starts empty and gets grants stored on the membership, chosen from 'grantable' below.
|
| transactions.edit  edit a posted document while its period is open (history is always kept)
| periods.reopen     edit or void inside a closed period or fiscal year; owner and admin only (via '*')
| invoices.approve   post an invoice; with settings.approval.invoice_above set, not your own above that amount
| contacts.edit      change or archive a customer or supplier (sales and purchasing staff only view and add)
| bills.create       enter a supplier's bill as a draft; bills.approve posts it (accountant, approver, owner, admin)
| bills.request      ask for something to be bought (084): every role that works, not viewers; bills.approve approves or
|                    rejects it (never one's own) and bills.create turns an approved one into a draft purchase order
| payments.receive   record a payment received; payments.reverse reverses one (accountant, owner, admin)
| payments.pay       pay suppliers' bills and refund customers (accountant via 'payments.*', owner, admin): money leaves the company
|                    (a refund received from a supplier needs payments.receive)
| expenses.create    record an expense already paid (fuel, utilities, petty cash): bookkeeper, accountant, owner, admin
| expenses.view      read expenses: also approvers and viewers
| expenses.claim     claim back what one paid for the company out of one's own pocket (083): every role that works, not viewers;
|                    the claimant sees and submits their own claims, someone with bills.approve posts them
| inventory.view     items, warehouses, stock on hand: every role that works with goods
| inventory.items    add and change items and warehouses (bookkeeper, purchasing, accountant)
| inventory.adjust   stock adjustments and opening stock, which post to the ledger (accountant, owner, admin)
| inventory.transfer move stock between warehouses; no ledger effect (bookkeeper, purchasing, accountant)
| inventory.receive  record or void a goods received note against a purchase order: stock in at the order's cost
|                    against goods received not invoiced (bookkeeper, purchasing, accountant via 'inventory.*')
| inventory.deliver  record or void a delivery note against a sales order: stock out at its cost, cost of goods
|                    sold recorded then (bookkeeper, sales, accountant via 'inventory.*')
| banking.view       bank, wallet and cash accounts with balances and registers (also viewers)
| banking.manage     open, rename, give bank details to and archive money accounts (bookkeeper, accountant via 'banking.*')
| banking.review     import statements and match, categorise (posts an entry) or exclude their lines (bookkeeper, accountant)
| banking.reconcile  reconcile an account with its statement, and undo the latest reconciliation with a reason (bookkeeper, accountant)
| banking.count      count a cash box, naming the counter; a difference posts to cash over and short (bookkeeper, accountant)
| company.view       read the company profile and fiscal settings, the chart of accounts (no balances) and the tax
|                    rates; every role, because every document form names accounts and taxes
| company.manage     change the profile and settings; owner and admin only (via '*')
| periods.close      move the period lock date forward
| accounting.rates   enter or correct exchange rates (accountant via 'accounting.*')
| accounting.opening enter or replace the opening balances (accountant via 'accounting.*')
| accounting.dimensions  add, rename and turn off locations, classes and projects (accountant via 'accounting.*')
| accounting.chart   add, rename, re-code, group and archive accounts of the chart (accountant via 'accounting.*')
| accounting.revalue revalue foreign-currency balances at a month end (accountant via 'accounting.*')
| journals.approve   approve or reject a manual journal above settings.approval.journal_above, not one you asked for
|                    (accountant via 'journals.*', approver)
| audit.view         the audit log, its export and the check of its hash chain (accountant; owner and admin)
| users.manage       the people of the company: invite, change roles, deactivate and reactivate (owner and admin only)
| company.export     download everything the company keeps as CSV files in a ZIP (owner and admin only)
| assets.view        the fixed asset register, each asset's depreciation schedule (accountant via 'assets.*',
|                    bookkeeper, approver, viewer)
| assets.manage      register fixed assets and edit them (accountant via 'assets.*')
| assets.depreciate  post the monthly depreciation run (accountant via 'assets.*')
| projects.view      projects with their income, costs and profit (accountant via 'projects.*', bookkeeper,
|                    approver, viewer)
| projects.manage    add projects and edit their details, status and budget; see and edit everyone's hours and bill
|                    them (accountant via 'projects.*')
| projects.time      log one's own hours against projects (accountant via 'projects.*', bookkeeper, sales,
|                    purchasing, approver)
| tax.view           the tax worksheets and filed returns (accountant via 'tax.*', viewer)
| tax.file           file a tax return and settle it (accountant via 'tax.*')
| journals.review    mark an entry of the review queue of unusual entries reviewed, with a note (accountant via
|                    'journals.*', approver)
*/

return [
    'roles' => [
        'owner' => ['*'],
        'admin' => ['*'],
        'accountant' => ['company.view', 'periods.close', 'payments.*', 'accounting.*', 'journals.*', 'transactions.edit', 'invoices.*', 'bills.*', 'expenses.*', 'inventory.*', 'banking.*', 'reports.*', 'tax.*', 'contacts.*', 'projects.*', 'assets.*', 'audit.view'],
        'bookkeeper' => ['company.view', 'payments.view', 'payments.receive', 'accounting.view', 'invoices.view', 'invoices.create', 'transactions.edit', 'bills.view', 'bills.create', 'expenses.view', 'expenses.create', 'expenses.claim', 'bills.request', 'inventory.view', 'inventory.items', 'inventory.transfer', 'inventory.receive', 'inventory.deliver', 'banking.*', 'contacts.*', 'reports.view', 'projects.view', 'projects.time', 'assets.view'],
        'sales' => ['company.view', 'payments.view', 'invoices.view', 'invoices.create', 'contacts.view', 'contacts.create', 'payments.receive', 'inventory.view', 'inventory.deliver', 'projects.time', 'expenses.claim', 'bills.request'],
        'purchasing' => ['company.view', 'bills.view', 'bills.create', 'contacts.view', 'contacts.create', 'inventory.view', 'inventory.items', 'inventory.transfer', 'inventory.receive', 'projects.time', 'expenses.claim', 'bills.request'],
        'approver' => ['company.view', 'payments.view', 'invoices.view', 'invoices.approve', 'bills.view', 'bills.approve', 'expenses.view', 'inventory.view', 'payments.approve', 'accounting.view', 'journals.approve', 'journals.review', 'projects.view', 'projects.time', 'assets.view', 'expenses.claim', 'bills.request'],
        'viewer' => ['company.view', 'payments.view', 'accounting.view', 'invoices.view', 'bills.view', 'expenses.view', 'inventory.view', 'banking.view', 'reports.view', 'tax.view', 'projects.view', 'assets.view'],
        'custom' => [],
    ],

    /*
    | What an owner or admin may give a custom role, one by one, with how the Team page names it. company.view is
    | always given too (every form names accounts and taxes). Left out on purpose: users.manage, company.export,
    | company.manage and periods.reopen stay with owners and admins (the database allows them to no one else).
    */
    'grantable' => [
        'invoices.view' => 'Sales: see invoices and credit notes',
        'invoices.create' => 'Sales: write invoices and credit notes as drafts',
        'invoices.approve' => 'Sales: post invoices',
        'payments.view' => 'Payments: see payments received and made',
        'payments.receive' => 'Payments: record money received',
        'payments.pay' => 'Payments: pay suppliers and refund customers',
        'payments.reverse' => 'Payments: reverse a payment',
        'contacts.view' => 'Contacts: see customers and suppliers',
        'contacts.create' => 'Contacts: add customers and suppliers',
        'contacts.edit' => 'Contacts: change or archive customers and suppliers',
        'bills.view' => 'Purchases: see bills',
        'bills.create' => 'Purchases: enter bills as drafts',
        'bills.approve' => 'Purchases: post bills',
        'bills.request' => 'Purchases: ask for something to be bought',
        'expenses.view' => 'Expenses: see expenses',
        'expenses.create' => 'Expenses: record expenses',
        'expenses.claim' => 'Expenses: claim back what one paid',
        'transactions.edit' => 'Edit posted invoices and bills (every change is kept in the history)',
        'inventory.view' => 'Inventory: see items and stock',
        'inventory.items' => 'Inventory: add and change items and warehouses',
        'inventory.transfer' => 'Inventory: move stock between warehouses',
        'inventory.adjust' => 'Inventory: adjust stock (posts to the ledger)',
        'inventory.receive' => 'Inventory: receive goods against purchase orders (posts to the ledger)',
        'inventory.deliver' => 'Inventory: deliver goods against sales orders (posts to the ledger)',
        'banking.view' => 'Banking: see accounts, balances and registers',
        'banking.manage' => 'Banking: open and change bank, wallet and cash accounts',
        'banking.review' => 'Banking: import statements and categorise lines',
        'banking.reconcile' => 'Banking: reconcile accounts',
        'banking.transfer' => 'Banking: transfer between accounts',
        'banking.deposit' => 'Banking: deposit cash and cheques',
        'banking.count' => 'Banking: count cash boxes',
        'accounting.view' => 'Accounting: see the chart with balances and the journal',
        'accounting.chart' => 'Accounting: change the chart of accounts',
        'accounting.dimensions' => 'Accounting: manage locations, classes and projects',
        'accounting.opening' => 'Accounting: enter opening balances',
        'accounting.rates' => 'Accounting: enter exchange rates',
        'accounting.revalue' => 'Accounting: revalue foreign currency balances',
        'journals.create' => 'Journals: write manual journal entries',
        'journals.approve' => 'Journals: approve manual journal entries',
        'journals.review' => 'Journals: review unusual entries',
        'periods.close' => 'Close periods',
        'reports.view' => 'Reports: see and export reports',
        'tax.view' => 'Tax: see worksheets and returns',
        'tax.manage' => 'Tax: manage tax rates',
        'tax.file' => 'Tax: file and settle returns',
        'projects.view' => 'Projects: see projects and their profit',
        'projects.manage' => 'Projects: manage projects and everyone\'s hours',
        'projects.time' => 'Projects: log one\'s own hours',
        'assets.view' => 'Fixed assets: see the register',
        'assets.manage' => 'Fixed assets: register and edit assets',
        'assets.depreciate' => 'Fixed assets: post depreciation',
        'audit.view' => 'See the audit log',
    ],
];
