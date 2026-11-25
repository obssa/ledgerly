<?php

use App\Support\SchemaSql;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Relay outbox events (webhooks, search indexing) and refresh FX rates. Jobs are added as modules land.
// Horizon needs Redis; on shared hosting the queue is in the database and worked by cron instead.
if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
Schedule::command('ledgerly:post-recurring')->hourly();
Schedule::command('ledgerly:make-recurring-invoices')->hourly();
Schedule::command('ledgerly:send-saved-reports')->hourly();
Schedule::command('ledgerly:send-invoice-reminders')->hourly();
Schedule::command('ledgerly:remind-scheduled-payments')->hourly();
Schedule::command('ledgerly:subscriptions-tick')->dailyAt('01:00');
Schedule::command('ledgerly:send-tax-reminders')->dailyAt('05:00');
Schedule::command('ledgerly:expire-quotes')->dailyAt('00:30');

/*
| Posts recurring journals that have come due (App\Domain\Ledger\RecurringJournals), each in its own company's
| context and as the person it posts as. Which templates are due comes from recurring_journals_due() (036): ids only.
| A template that fails is paused with the reason by RecurringJournals; an unexpected error is reported and the
| others still post.
*/
Artisan::command('ledgerly:post-recurring', function (\App\Domain\Ledger\RecurringJournals $recurring, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    // Each company's own today decides; tomorrow in UTC is the latest any company's today can be.
    $due = DB::select('SELECT company_id, template_id, posts_as FROM recurring_journals_due(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $posted = 0;
    foreach ($due as $row) {
        try {
            $result = \App\Domain\Tenancy\Support\DatabaseTenantContext::run($row->posts_as, $row->company_id, function () use ($recurring, $current, $row) {
                $current->set(\App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id));

                return $recurring->postDue($current->get(), $row->template_id, $row->posts_as, $current->today());
            });
            $posted += count($result['posted']);
            if ($result['error'] !== null) {
                $this->warn("Recurring journal {$row->template_id} paused: {$result['error']}");
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error("Recurring journal {$row->template_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Posted {$posted} recurring journal entries.");

    return 0;
})->purpose('Post recurring journals that have come due');

/*
| Makes the recurring invoices that have come due (077, App\Domain\Sales\RecurringInvoices), each in its own company's
| context and as the person it makes invoices as. Which are due comes from recurring_invoices_due(): ids only. A template
| that fails is paused with the reason; an unexpected error is reported and the others still run.
*/
Artisan::command('ledgerly:make-recurring-invoices', function (\App\Domain\Sales\RecurringInvoices $recurring, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    $due = DB::select('SELECT company_id, template_id, posts_as FROM recurring_invoices_due(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $made = 0;
    foreach ($due as $row) {
        try {
            $result = \App\Domain\Tenancy\Support\DatabaseTenantContext::run($row->posts_as, $row->company_id, function () use ($recurring, $current, $row) {
                $current->set(\App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id));

                return $recurring->makeDue($current->get(), $row->template_id, $row->posts_as, $current->today());
            });
            $made += count($result['made']);
            if ($result['error'] !== null) {
                $this->warn("Recurring invoice {$row->template_id} paused: {$result['error']}");
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error("Recurring invoice {$row->template_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Made {$made} recurring invoices.");

    return 0;
})->purpose('Make the recurring invoices that have come due');

/*
| Sends the scheduled reports that have come due (045): an email to the person who saved each one, with a link to it and
| no figures. Each runs in its own company's context as that person; due on the company's own today. Someone who no
| longer reads reports gets nothing, and the schedule moves on. Which are due comes from saved_reports_due(): ids only.
*/
Artisan::command('ledgerly:send-saved-reports', function (\App\Domain\Reports\SavedReports $saved, \App\Domain\Tenancy\Support\CurrentCompany $current, \App\Domain\Audit\AuditLogger $audit) {
    $due = DB::select('SELECT company_id, saved_report_id, user_id FROM saved_reports_due(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $sent = 0;
    foreach ($due as $row) {
        try {
            $sent += \App\Domain\Tenancy\Support\DatabaseTenantContext::run($row->user_id, $row->company_id, function () use ($saved, $current, $audit, $row) {
                $company = \App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id);
                $current->set($company);
                $today = $current->today();
                $report = DB::table('saved_reports')->where(['id' => $row->saved_report_id, 'company_id' => $row->company_id, 'user_id' => $row->user_id])->first();
                if ($report === null || $report->next_send_on > $today) {
                    return 0;   // not yet due in the company's own time zone
                }
                $member = \App\Domain\Tenancy\Models\Membership::query()->where(['company_id' => $row->company_id, 'user_id' => $row->user_id, 'status' => 'active'])->first();
                $send = $member !== null && $member->can('reports.view');
                if ($send) {
                    $dates = $saved->period($row->company_id, $report->period, $report->from_date, $report->to_date, $today);
                    $covers = in_array($report->report, \App\Domain\Reports\SavedReports::AT_A_DATE, true) ? "at {$dates['to']}" : "{$dates['from']} to {$dates['to']}";
                    \App\Models\User::query()->findOrFail($row->user_id)->notify(new \App\Notifications\SavedReportReady($report->id, $report->name, (string) $company->legal_name, $covers));
                    $audit->record($row->company_id, 'saved_report_sent', 'saved_report', $report->id, null, ['name' => $report->name, 'covers' => $covers], userId: $row->user_id);
                }
                DB::table('saved_reports')->where('id', $report->id)->update(['next_send_on' => $saved->nextSend($report->schedule, $today), 'last_sent_at' => $send ? now() : $report->last_sent_at]);

                return $send ? 1 : 0;
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error("Saved report {$row->saved_report_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Sent {$sent} scheduled reports.");

    return 0;
})->purpose('Email the scheduled reports that have come due');

/*
| Sends the payment reminders that have come due (048): for each company with reminders on, an email to the customer
| so many days after an invoice fell due, while something is still owed. Which are due comes from
| invoice_reminders_due(): ids only. Each runs in its own company's context and is due on the company's own today;
| a step goes once per invoice. A customer without an email, or an invoice paid meanwhile, is skipped.
*/
Artisan::command('ledgerly:send-invoice-reminders', function (\App\Domain\Sales\InvoiceReminders $reminders, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    $due = DB::select('SELECT company_id, document_id, step FROM invoice_reminders_due(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $sent = 0;
    foreach ($due as $row) {
        try {
            $sent += \App\Domain\Tenancy\Support\DatabaseTenantContext::run(null, $row->company_id, function () use ($reminders, $current, $row) {
                $company = \App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id);
                $current->set($company);
                $invoice = \App\Domain\Sales\Models\Document::query()->where('company_id', $row->company_id)->find($row->document_id);
                if ($invoice === null || $invoice->due_date === null || $invoice->due_date->copy()->addDays((int) $row->step)->format('Y-m-d') > $current->today()) {
                    return 0;   // not yet due in the company's own time zone
                }
                $reminders->send($company, $invoice, 'scheduled', (int) $row->step, null);

                return 1;
            });
        } catch (\Illuminate\Validation\ValidationException) {
            // paid or changed since the list was read: nothing to remind
        } catch (\Throwable $e) {
            report($e);
            $this->error("Reminder for invoice {$row->document_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Sent {$sent} payment reminders.");

    return 0;
})->purpose('Email the payment reminders that have come due');

/*
| Reminders of scheduled supplier payments (103): on the day a payment is scheduled for (the company's own today), an
| email to the person who scheduled it, or, when they can no longer pay bills there, to everyone who can. Once per
| payment (reminded_on is set once). Which are due comes from scheduled_payments_due(): ids only. Nothing is recorded:
| the payment waits for someone to confirm the money went out.
*/
Artisan::command('ledgerly:remind-scheduled-payments', function (\App\Domain\Purchases\ScheduledPayments $plans, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    $due = DB::select('SELECT company_id, scheduled_payment_id FROM scheduled_payments_due(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $sent = 0;
    foreach ($due as $row) {
        try {
            $sent += \App\Domain\Tenancy\Support\DatabaseTenantContext::run(null, $row->company_id, function () use ($plans, $current, $row) {
                $company = \App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id);
                $current->set($company);
                $today = $current->today();
                $plan = DB::table('scheduled_payments')->where(['company_id' => $row->company_id, 'id' => $row->scheduled_payment_id, 'status' => 'scheduled'])
                    ->whereNull('reminded_on')->first();
                if ($plan === null || substr((string) $plan->pay_on, 0, 10) > $today) {
                    return 0;   // confirmed, cancelled or not yet due in the company's own time zone
                }
                $members = \App\Domain\Tenancy\Models\Membership::query()->where(['company_id' => $company->getKey(), 'status' => 'active'])->get()
                    ->filter(fn ($m) => $m->can('payments.pay'));
                $ids = $members->contains(fn ($m) => $m->user_id === $plan->created_by) ? [$plan->created_by] : $members->pluck('user_id')->all();
                DB::table('scheduled_payments')->where(['company_id' => $row->company_id, 'id' => $plan->id])->update(['reminded_on' => $today, 'updated_at' => now()]);
                $one = $plans->one($plan->id);
                foreach (\App\Models\User::query()->whereIn('id', $ids)->get() as $person) {
                    $person->notify(new \App\Notifications\ScheduledPaymentDue((string) ($company->trading_name ?: $company->legal_name), $one['contact']['name'],
                        $one['pay_on'], $one['currency'], $one['amount'], $plan->id));
                }

                return 1;
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error("Reminder for scheduled payment {$row->scheduled_payment_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Sent {$sent} scheduled payment reminders.");

    return 0;
})->purpose('Email the people who pay bills about payments scheduled for today');

/*
| Ledgerly's own subscriptions (053): once a day, a trial past its end is suspended, a subscription past its paid-until
| date becomes past due, and past due beyond the grace days is suspended (suspended books are read-only). Only while
| billing is enforced (config/billing.php); never the shared demo. Runs as the application, outside any company.
*/
Artisan::command('ledgerly:subscriptions-tick', function () {
    if (! config('billing.enforce')) {
        $this->info('Billing is not enforced (BILLING_ENFORCE): nothing moves.');

        return 0;
    }
    $moved = (int) DB::selectOne('SELECT subscriptions_tick(?::date, ?) AS n', [now('Africa/Addis_Ababa')->format('Y-m-d'), (int) config('billing.grace_days')])->n;
    $this->info("{$moved} subscriptions moved.");

    return 0;
})->purpose('Move subscriptions on with time: trials ended, payments overdue');

/*
| The operator's hand on a subscription (053), run as the owner role (the application cannot change it): record a payment
| (--activate --paid-until), change the plan, extend a trial, suspend or cancel. Every change is kept in
| subscription_events with who did it. Usually run through .github/workflows/billing.yml.
*/
Artisan::command('ledgerly:subscription {organisation : the organisation id, or the id of one of its companies}
        {--plan= : starter, essentials, plus, advanced or accountant}
        {--activate : the subscription is paid: active}
        {--paid-until= : the last day paid for (Y-m-d), with --activate}
        {--extend-trial= : days more of trial, from today or the trial end, whichever is later}
        {--suspend : suspend now (read-only books)}
        {--cancel : cancel (read-only; the books are kept for the statutory period)}
        {--reference= : the bank transfer reference}
        {--note= : a note for the record}
        {--by=operator : who is doing this}', function () {
    $db = DB::connection('owner');
    $id = (string) $this->argument('organisation');
    if (! \Illuminate\Support\Str::isUuid($id)) {
        $this->error('Give an organisation or company id.');

        return 1;
    }
    $org = $db->table('organisations')->where('id', $id)->first()
        ?? $db->table('organisations')->where('id', $db->table('companies')->where('id', $id)->value('organisation_id'))->first();
    if ($org === null) {
        $this->error('No such organisation or company.');

        return 1;
    }
    $actions = array_filter(['activate' => $this->option('activate'), 'extend' => $this->option('extend-trial') !== null,
        'suspend' => $this->option('suspend'), 'cancel' => $this->option('cancel')]);
    if (count($actions) > 1) {
        $this->error('One of --activate, --extend-trial, --suspend or --cancel at a time.');

        return 1;
    }
    $plan = $this->option('plan');
    if ($plan !== null && ! array_key_exists($plan, config('billing.plans'))) {
        $this->error('Unknown plan: ' . implode(', ', array_keys(config('billing.plans'))));

        return 1;
    }
    $set = $plan !== null ? ['plan' => $plan] : [];
    $action = $plan !== null ? 'plan_changed' : null;
    if ($this->option('activate')) {
        $until = (string) $this->option('paid-until');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < now()->format('Y-m-d')) {
            $this->error('--paid-until is the last day paid for, today or later (Y-m-d).');

            return 1;
        }
        $set += ['status' => 'active', 'paid_until' => $until, 'past_due_since' => null];
        $action = 'payment_recorded';
    } elseif ($this->option('extend-trial') !== null) {
        $days = (int) $this->option('extend-trial');
        if ($org->status !== 'trial' || $days < 1 || $days > 90) {
            $this->error('Only a trial is extended, by 1 to 90 days.');

            return 1;
        }
        $from = max(now(), \Illuminate\Support\Carbon::parse((string) ($org->trial_ends_at ?? now())));
        $set += ['trial_ends_at' => $from->copy()->addDays($days)];
        $action = 'trial_extended';
    } elseif ($this->option('suspend')) {
        $set += ['status' => 'suspended'];
        $action = 'suspended';
    } elseif ($this->option('cancel')) {
        $set += ['status' => 'cancelled', 'cancelled_at' => now(), 'delete_after' => now()->addYears(10)->format('Y-m-d')];
        $action = 'cancelled';
    }
    if ($action === null) {
        $this->line("Plan {$org->plan}, status {$org->status}, trial ends " . ($org->trial_ends_at ?? '-') . ', paid until ' . ($org->paid_until ?? '-') . '.');

        return 0;
    }
    try {
        $db->transaction(function () use ($db, $org, $set, $action) {
            $db->table('organisations')->where('id', $org->id)->update([...$set, 'updated_at' => now()]);
            $after = $db->table('organisations')->where('id', $org->id)->first();
            $db->table('subscription_events')->insert([
                'organisation_id' => $org->id, 'action' => $action, 'from_status' => $org->status, 'to_status' => $after->status,
                'plan' => $after->plan, 'paid_until' => $after->paid_until, 'trial_ends_at' => $after->trial_ends_at,
                'reference' => $this->option('reference'), 'note' => $this->option('note'), 'by_whom' => mb_substr((string) $this->option('by'), 0, 120),
            ]);
        });
    } catch (\Illuminate\Database\QueryException $e) {
        $this->error('Refused: ' . ($e->errorInfo[2] ?? $e->getMessage()));

        return 1;
    }
    $now = $db->table('organisations')->where('id', $org->id)->first();
    $this->info("{$action}: plan {$now->plan}, status {$now->status}, trial ends " . ($now->trial_ends_at ?? '-') . ', paid until ' . ($now->paid_until ?? '-') . '.');

    return 0;
})->purpose("Record a payment or change a company's Ledgerly subscription (owner role)");

/*
| Reminders of tax returns that are due (054): from each company's filing calendar (the country pack's due dates), an
| email to the people who may file tax when a return is due within ten days, and again when it has just become overdue
| (within a week of its due date, so a company new to Ledgerly is not sent its whole past). Once per period and stage.
| Which companies come from tax_reminder_companies(): in use, not the demo, reminders not turned off.
*/
Artisan::command('ledgerly:send-tax-reminders', function (\App\Domain\Tax\TaxCalendar $calendar, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    $sent = 0;
    foreach (DB::select('SELECT company_id FROM tax_reminder_companies()') as $row) {
        try {
            $sent += \App\Domain\Tenancy\Support\DatabaseTenantContext::run(null, $row->company_id, function () use ($calendar, $current, $row) {
                $company = \App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id);
                $current->set($company);
                $today = $current->today();
                $soonest = (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
                $members = \App\Domain\Tenancy\Models\Membership::query()->where(['company_id' => $company->getKey(), 'status' => 'active'])->get()
                    ->filter(fn ($m) => $m->can('tax.file'));
                $people = \App\Models\User::query()->whereIn('id', $members->pluck('user_id'))->get();
                $count = 0;
                foreach ($calendar->for($company, $today)['kinds'] as $kind => $k) {
                    foreach ($k['periods'] as $p) {
                        $stage = match (true) {
                            $p['status'] === 'due_soon' => 'due_soon',
                            $p['status'] === 'overdue' && $p['due_on'] >= $soonest => 'overdue',
                            default => null,
                        };
                        if ($stage === null || DB::table('tax_reminders')->where(['company_id' => $company->getKey(), 'kind' => $kind,
                            'period_start' => $p['period_start'], 'stage' => $stage])->exists()) {
                            continue;
                        }
                        DB::table('tax_reminders')->insert(['company_id' => $company->getKey(), 'kind' => $kind, 'period_start' => $p['period_start'],
                            'period_end' => $p['period_end'], 'due_on' => $p['due_on'], 'stage' => $stage, 'sent_to' => $people->count()]);
                        foreach ($people as $person) {
                            $person->notify(new \App\Notifications\TaxReturnDue((string) ($company->trading_name ?: $company->legal_name), $kind,
                                $p['period_start'], $p['period_end'], $p['due_on'], $stage));
                        }
                        $count++;
                    }
                }

                return $count;
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error("Tax reminders for company {$row->company_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("{$sent} tax return reminders sent.");

    return 0;
})->purpose('Email the people who file tax about returns due soon or just overdue');

/*
| Run before `migrate` when the tables live outside `public` (DB_SCHEMA, shared hosting): Laravel creates its
| migrations table before the first migration runs, so the schema must already exist. The name comes from
| configuration and is checked to be a plain identifier (SchemaSql).
*/
Artisan::command('ledgerly:create-schema {--database=owner}', function () {
    $schema = SchemaSql::schema();
    DB::connection($this->option('database'))->statement('CREATE SCHEMA IF NOT EXISTS "' . $schema . '"');
    $this->info("Schema {$schema} is ready.");
})->purpose('Create the schema the Ledgerly tables live in (DB_SCHEMA)');

/*
| Run by the deploy after migrating, on the application's own connection: refuses to go live if the login the
| API runs as could bypass row-level security or change the database (App\Support\DatabaseRoleCheck).
*/
Artisan::command('ledgerly:check-database-roles', function () {
    ['problems' => $problems, 'warnings' => $warnings] = \App\Support\DatabaseRoleCheck::report();
    foreach ($warnings as $warning) {
        $this->warn($warning);
    }
    foreach ($problems as $problem) {
        $this->error($problem);
    }
    if ($problems === []) {
        $this->info('The application login is restricted: row-level security applies to it.');
    }

    return $problems === [] ? 0 : 1;
})->purpose('Check that the application database login cannot bypass row-level security');

/*
| Run by the deploy as the owner role when DEMO_LOGINS=true: the demo company and one shared account per role
| (App\Domain\Identity\DemoSetup). Adds only what is missing, so it is safe on every deploy.
*/
Artisan::command('ledgerly:demo-setup', function (\App\Domain\Identity\DemoSetup $setup) {
    if (! config('demo.enabled')) {
        $this->info('Demo sign-in is off (DEMO_LOGINS): nothing to set up.');

        return 0;
    }
    $setup->run(fn (string $line) => $this->info($line));

    return 0;
})->purpose('Set up the demo company and its shared sign-in accounts (DEMO_LOGINS=true)');

/*
| Quotes sent and unanswered past their valid-until date expire (056), each in its own company's context and checked
| against that company's today. Which quotes comes from quotes_expiring() (ids only); one that fails is reported.
*/
Artisan::command('ledgerly:expire-quotes', function (\App\Domain\Sales\Actions\QuoteWorkflow $workflow, \App\Domain\Tenancy\Support\CurrentCompany $current) {
    $due = DB::select('SELECT company_id, document_id FROM quotes_expiring(?)', [now('UTC')->addDay()->format('Y-m-d')]);
    $expired = 0;
    foreach ($due as $row) {
        try {
            $expired += \App\Domain\Tenancy\Support\DatabaseTenantContext::run(null, $row->company_id, function () use ($workflow, $current, $row) {
                $current->set(\App\Domain\Tenancy\Models\Company::query()->findOrFail($row->company_id));
                $quote = \App\Domain\Sales\Models\Document::query()->where('company_id', $row->company_id)->find($row->document_id);

                return $quote !== null && $workflow->expire($quote) ? 1 : 0;
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error("Quote {$row->document_id} failed: " . $e::class);
        } finally {
            $current->forget();
        }
    }
    $this->info("Quotes expired: {$expired}.");
})->purpose('Expire quotes past their valid-until date');

/*
| Staff accounts (066, SECURITY.md S8), by an operator only: run as the owner role (.github/workflows/staff.yml, the
| owner password given for this one run), never from the API. A new account, or --resend, mails a setup link (48 hours)
| where the person sets a password and an authenticator; --deactivate switches the account off and ends its sign-ins.
*/
Artisan::command('ledgerly:staff {email : the staff member\'s email} {--name= : their name (a new account)} {--resend : a new setup link}
        {--deactivate : switch the account off} {--reactivate : with --resend, switch a deactivated account on again} {--by=operator : who is doing this}', function () {
    $db = DB::connection('owner');
    $email = mb_strtolower(trim((string) $this->argument('email')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $this->error('Give an email address.');

        return 1;
    }
    $staff = $db->table('staff_users')->where('email', $email)->first(['id', 'name', 'active']);
    if ($this->option('deactivate')) {
        if ($staff === null) {
            $this->error('No staff account with that email.');

            return 1;
        }
        $db->transaction(function () use ($db, $staff) {
            $db->table('staff_users')->where('id', $staff->id)->update(['active' => false, 'setup_hash' => null, 'setup_expires_at' => null]);
            $db->table('staff_tokens')->where('staff_id', $staff->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $db->table('staff_sessions')->where('staff_id', $staff->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $db->table('staff_log')->insert(['staff_id' => $staff->id, 'action' => 'account_deactivated', 'detail' => json_encode(['by' => (string) $this->option('by')])]);
        });
        $this->info('Switched off; its sign-ins and sessions are ended.');

        return 0;
    }
    $name = trim((string) ($this->option('name') ?? $staff?->name ?? ''));
    if ($staff === null && mb_strlen($name) < 2) {
        $this->error('Give --name for a new staff account.');

        return 1;
    }
    $token = bin2hex(random_bytes(32));
    $hours = (int) config('staff.setup_hours');
    $fields = ['setup_hash' => hash('sha256', $token), 'setup_expires_at' => now()->addHours($hours)];
    if ($staff === null) {
        $id = (string) \Illuminate\Support\Str::uuid7();
        $db->table('staff_users')->insert(['id' => $id, 'email' => $email, 'name' => $name, ...$fields]);
        $action = 'account_created';
    } elseif ($this->option('resend') && ! $staff->active && ! $this->option('reactivate')) {
        $this->error('That staff account is switched off. Add --reactivate to switch it on again with a new setup link.');

        return 1;
    } elseif ($this->option('resend')) {
        $id = $staff->id;
        $db->table('staff_users')->where('id', $id)->update(['active' => true, 'password' => null, 'mfa_secret' => null, 'mfa_last_step' => null, ...$fields]);
        $db->table('staff_tokens')->where('staff_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $action = 'setup_link_resent';
    } else {
        $this->error('That staff account exists: --resend for a new setup link, or --deactivate.');

        return 1;
    }
    $db->table('staff_log')->insert(['staff_id' => $id, 'action' => $action, 'detail' => json_encode(['by' => (string) $this->option('by')])]);
    \Illuminate\Support\Facades\Notification::route('mail', [$email => $name])->notify(new \App\Notifications\StaffSetupLink($name, $token, $hours));
    $this->info("Setup link mailed to {$email}; it works for {$hours} hours.");

    return 0;
})->purpose('Make, re-invite or switch off a Ledgerly staff account (owner role)');

/*
| A test company (104): real books the operator keeps for trying Ledgerly out, run as the owner role. It
|  - adds each --owner as an active owner of the company: an account that does not exist yet is made with a
|    generated password, written only to --accounts-file (mode 600), never printed or mailed;
|  - takes the company out of the shared demo, if it was in it: its demo accounts stop being demo accounts, lose
|    their membership and their sign-ins, and get a retired address so a later demo setup can make new ones;
|  - drops " (demo)" from its name and marks it as a test company, so its owners may start it fresh and fill it with
|    the sample books (DemoBooksController).
| Every change is in the company's audit log. Safe to run again. Usually through .github/workflows/test-company.yml.
*/
Artisan::command('ledgerly:test-company {company : the company id}
        {--owner=* : an owner as "email|Name"; repeat for more}
        {--accounts-file= : where to write the passwords of accounts made now (mode 600)}
        {--by=operator : who is doing this}', function () {
    $db = DB::connection('owner');
    $companyId = (string) $this->argument('company');
    $by = preg_match('/^[A-Za-z0-9-]{1,40}$/', (string) $this->option('by')) === 1 ? (string) $this->option('by') : 'operator';
    if (! \Illuminate\Support\Str::isUuid($companyId)) {
        $this->error('Give the company id.');

        return 1;
    }
    $company = $db->table('companies')->where('id', $companyId)->first(['id', 'legal_name', 'status']);
    if ($company === null || $company->status === 'archived') {
        $this->error('No such company, or it is archived.');

        return 1;
    }

    $owners = [];
    foreach ((array) $this->option('owner') as $given) {
        [$email, $name] = array_map('trim', explode('|', (string) $given, 2) + [1 => '']);
        $email = mb_strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match("/^[A-Za-z0-9 .'-]{2,120}$/", $name) !== 1) {
            $this->error('Each --owner is "email|Name": a valid email, and a name of letters, digits, spaces and . \' - only.');

            return 1;
        }
        $owners[$email] = $name;
    }
    $file = (string) ($this->option('accounts-file') ?? '');
    if ($file === '' && $owners !== []) {
        $this->error('Give --accounts-file: new accounts get a password that is written only there.');

        return 1;
    }

    // Letters and digits only (nothing a shell or a form mangles), 20 of them: about 119 bits.
    $password = function (): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        do {
            $p = '';
            for ($i = 0; $i < 20; $i++) {
                $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (! preg_match('/[A-Z]/', $p) || ! preg_match('/[a-z]/', $p) || ! preg_match('/\d/', $p));

        return $p;
    };

    $made = [];
    $summary = $db->transaction(function () use ($db, $company, $owners, $password, $by, &$made) {
        $audit = function (string $action, string $subjectType, ?string $subjectId, ?array $before, ?array $after) use ($db, $company, $by) {
            $db->table('audit_log')->insert([
                'company_id' => $company->id, 'user_id' => null, 'actor_type' => 'system', 'action' => $action,
                'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
                'reason' => "Test company set up by the operator ({$by})",
            ]);
        };

        // 1. The owners first: a company always keeps an active owner (008), and the demo's owner is about to go.
        foreach ($owners as $email => $name) {
            $user = $db->table('users')->where('email', $email)->first(['id']);
            if ($user === null) {
                $id = (string) \Illuminate\Support\Str::uuid();
                $plain = $password();
                $db->table('users')->insert(['id' => $id, 'name' => $name, 'email' => $email,
                    'password' => \Illuminate\Support\Facades\Hash::make($plain), 'email_verified_at' => now()]);
                $made[] = ['email' => $email, 'name' => $name, 'password' => $plain];
            } else {
                $id = (string) $user->id;
            }
            $membership = $db->table('memberships')->where(['company_id' => $company->id, 'user_id' => $id])->first(['role', 'status']);
            if ($membership === null) {
                $db->table('memberships')->insert(['company_id' => $company->id, 'user_id' => $id, 'role' => 'owner', 'status' => 'active']);
                $audit('member_added', 'membership', $id, null, ['email' => $email, 'role' => 'owner', 'status' => 'active']);
            } elseif ($membership->role !== 'owner' || $membership->status !== 'active') {
                $db->table('memberships')->where(['company_id' => $company->id, 'user_id' => $id])->update(['role' => 'owner', 'status' => 'active']);
                $audit('member_role_changed', 'membership', $id, ['role' => $membership->role, 'status' => $membership->status], ['role' => 'owner', 'status' => 'active']);
            }
        }

        // 2. Out of the shared demo: its accounts are no longer demo accounts, members, or signed in anywhere.
        $demo = $db->table('demo_accounts')->where('company_id', $company->id)->pluck('user_id')->map(fn ($u) => (string) $u)->all();
        if ($demo !== []) {
            $db->table('demo_accounts')->where('company_id', $company->id)->delete();
            $db->table('memberships')->where('company_id', $company->id)->whereIn('user_id', $demo)->where('status', '<>', 'deactivated')
                ->update(['status' => 'deactivated']);
            $db->table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $demo)->delete();
            $stamp = now()->format('YmdHis');
            foreach ($db->table('users')->whereIn('id', $demo)->get(['id', 'email']) as $u) {
                if (str_ends_with((string) $u->email, '@demo.ledgerly.invalid')) {
                    $local = strstr((string) $u->email, '@', true);
                    $db->table('users')->where('id', $u->id)->update(['email' => "{$local}.retired-{$stamp}@demo.ledgerly.invalid"]);
                }
            }
            $audit('demo_accounts_removed', 'company', $company->id, ['demo_accounts' => count($demo)], ['demo_accounts' => 0]);
        }

        // 3. Its own name, and the mark.
        $name = preg_replace('/\s*\(demo\)\s*$/i', '', (string) $company->legal_name);
        if ($name !== $company->legal_name && $name !== '') {
            $db->table('companies')->where('id', $company->id)->update(['legal_name' => $name, 'updated_at' => now()]);
            $audit('company_updated', 'company', $company->id, ['legal_name' => $company->legal_name], ['legal_name' => $name]);
        }
        if (! $db->table('test_companies')->where('company_id', $company->id)->exists()) {
            $db->table('test_companies')->insert(['company_id' => $company->id, 'marked_by' => $by]);
            $audit('test_company_marked', 'company', $company->id, null, ['test_company' => true]);
        }

        return ['demo_removed' => count($demo), 'name' => $name];
    });

    if ($made !== []) {
        $old = umask(077);
        try {
            $lines = array_map(fn ($a) => "{$a['name']}\t{$a['email']}\t{$a['password']}", $made);
            file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND);
            chmod($file, 0600);
        } finally {
            umask($old);
        }
    }
    $this->info("Test company: {$summary['name']} ({$company->id}).");
    $this->info(count($owners) . ' owner(s) checked; ' . count($made) . ' new account(s), their passwords written to the accounts file only.');
    $this->info("Demo accounts taken out of it: {$summary['demo_removed']}.");

    return 0;
})->purpose('Make a company a test company with the given owners, out of the shared demo (owner role)');
