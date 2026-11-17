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
Schedule::command('ledgerly:send-saved-reports')->hourly();
Schedule::command('ledgerly:send-invoice-reminders')->hourly();

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
