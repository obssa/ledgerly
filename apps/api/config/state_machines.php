<?php

/*
| State machines: machine => from state => states it may move to. '' = "may be created as".
| A state that is not a key (or has an empty list) is final.
|
| The database enforces the same list (database/schema/006_state_machines.sql); Tests\Unit\StateMachineTest
| fails if the two ever differ. Change both in one commit, with a migration for the database side.
*/

return [
    // Subscription (organisations.status). suspended and cancelled are read-only.
    'subscription' => [
        '' => ['trial'],
        'trial' => ['active', 'suspended', 'cancelled'],
        'active' => ['past_due', 'cancelled'],
        'past_due' => ['active', 'suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
    ],

    // Company
    'company' => [
        '' => ['setting_up'],
        'setting_up' => ['live'],
        'live' => ['archived'],
        'archived' => ['live'],
    ],

    // User membership
    'membership' => [
        '' => ['invited', 'active'],
        'invited' => ['active', 'deactivated'],
        'active' => ['deactivated'],
        'deactivated' => ['active'],
    ],

    // Fiscal year
    'fiscal_year' => [
        '' => ['open'],
        'open' => ['closing'],
        'closing' => ['open', 'closed'],
        'closed' => ['reopened'],
        'reopened' => ['closed'],
    ],

    // Journal entry. A posted entry is never edited: an edit is a reversal plus a new entry.
    'journal_entry' => [
        '' => ['posted'],
        'posted' => ['reversed'],
    ],

    // Invoice, bill, credit note, supplier credit, cash sale. part_paid and paid follow amount_due.
    'invoice' => [
        '' => ['draft'],
        'draft' => ['awaiting_approval', 'posted', 'void'],
        'awaiting_approval' => ['posted', 'draft'],
        'posted' => ['part_paid', 'paid', 'void'],
        'part_paid' => ['paid', 'posted'],
        'paid' => ['part_paid', 'posted'],
    ],

    // Quote, sales order, purchase order
    'quote' => [
        '' => ['draft'],
        'draft' => ['sent', 'accepted', 'void'],
        'sent' => ['accepted', 'declined', 'expired', 'void'],
        'expired' => ['sent'],
        'accepted' => ['converted', 'void'],   // a confirmed order or an accepted quote can still be cancelled (056)
    ],

    // Payment
    'payment' => [
        '' => ['posted'],
        'posted' => ['reversed'],
    ],

    // E-invoice submission. cleared is final: a cleared invoice is corrected by credit note.
    'einvoice' => [
        '' => ['queued'],
        'queued' => ['submitted', 'cleared', 'rejected', 'failed'],
        'submitted' => ['cleared', 'rejected', 'failed'],
        'failed' => ['queued', 'submitted', 'cleared', 'rejected'],
        'rejected' => ['queued'],
    ],

    // Bank statement line
    'bank_transaction' => [
        '' => ['imported'],
        'imported' => ['matched', 'categorised', 'excluded'],
        'matched' => ['imported', 'reconciled'],
        'categorised' => ['imported', 'reconciled'],
        'excluded' => ['imported'],
        'reconciled' => ['matched', 'categorised'],
    ],

    // Project (102). A finished project is reopened to active before anything else.
    'project' => [
        '' => ['active', 'on_hold', 'completed', 'cancelled'],
        'active' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['active', 'completed', 'cancelled'],
        'completed' => ['active'],
        'cancelled' => ['active'],
    ],

    // Lease (102). ended is final.
    'lease' => [
        '' => ['active'],
        'active' => ['ended'],
    ],

    // Scheduled supplier payment (103). Confirmed once (paid) or cancelled; both are final.
    'scheduled_payment' => [
        '' => ['scheduled'],
        'scheduled' => ['paid', 'cancelled'],
    ],
];
