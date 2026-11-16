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
