<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\Models\BelongsToCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md task S1: two companies, and company B's ids sent by a user of company A in the header, the URL
 * and the body. The answer is 403 or 404 (or a validation refusal), never B's data.
 *
 * The route list is read from the router, so a new company route is covered the moment it exists, and a new
 * route parameter fails the suite until it gets a fixture below.
 *
 * Each wall is also tested on its own, so removing one is caught even though the other would still hold:
 *   - row-level security, with the Eloquent company scope switched off;
 *   - the Eloquent company scope, on the owner connection where row-level security does not apply.
 */
final class TenantIsolationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $a;

    /** @var array<string, string> */
    private array $b;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();
        // Every company route, twice over, as one user: more than the API's 120 a minute. Not what this test is about.
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);

        $this->b = $this->companyWithRecords('b@example.test', 'Company B Trading');
        $this->a = $this->companyWithRecords('a@example.test', 'Company A Trading');
        $this->userA = $this->lastUser;
        $this->actingAs($this->userA, 'sanctum');
    }

    public function test_another_companys_id_in_the_header_is_refused_on_every_company_route(): void
    {
        foreach ($this->companyRoutes() as $route) {
            foreach ([$this->b, $this->a] as $ids) {   // B's records and A's: the header alone decides
                $response = $this->callRoute($route, $ids, ['X-Company-Id' => $this->b['company']]);
                $this->assertSame(403, $response->status(), $this->describe($route, 'header of company B', $response));
                $this->assertNoDataOf($this->b, $response, $route);
            }
        }
    }

    public function test_another_companys_id_in_the_url_is_not_found(): void
    {
        $routes = array_filter($this->companyRoutes(), fn (Route $r) => $r->parameterNames() !== []);
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->callRoute($route, $this->b, ['X-Company-Id' => $this->a['company']]);
            $this->assertSame(404, $response->status(), $this->describe($route, "company B's id in the URL", $response));
            $this->assertNoDataOf($this->b, $response, $route);

            // Control: the same route with A's own record is found, so the 404 above is isolation, not a broken route.
            $own = $this->callRoute($route, $this->a, ['X-Company-Id' => $this->a['company']]);
            $this->assertNotSame(404, $own->status(), $this->describe($route, "company A's own id", $own));
            $this->assertLessThan(500, $own->status(), $this->describe($route, "company A's own id", $own));
        }
    }

    public function test_lists_and_reports_never_contain_another_companys_records(): void
    {
        $routes = array_filter($this->companyRoutes(), fn (Route $r) => in_array('GET', $r->methods(), true) && $r->parameterNames() === []);
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->callRoute($route, $this->b, ['X-Company-Id' => $this->a['company']]);
            $this->assertSame(200, $response->status(), $this->describe($route, 'company A', $response));
            $this->assertNoDataOf($this->b, $response, $route);
        }

        // Outside a company: the company switcher lists only the user's own companies.
        $companies = $this->getJson('/api/v1/companies')->assertOk();
        $this->assertSame([$this->a['company']], array_column($companies->json('data'), 'id'));
    }

    public function test_another_companys_ids_in_the_body_are_refused_and_nothing_is_written(): void
    {
        $headers = ['X-Company-Id' => $this->a['company']];
        $line = fn (array $over = []) => [...['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->a['sales'], 'tax_rate_id' => $this->a['tax_rate']], ...$over];
        $invoice = fn (array $over = [], array $lineOver = []) => [
            ...['contact_id' => $this->a['contact'], 'issue_date' => '2026-11-03', 'currency' => 'ETB', 'lines' => [$line($lineOver)]],
            ...$over,
        ];
        $before = $this->ledgerCounts();

        $attempts = [
            'invoice for B\'s customer' => ['POST', '/api/v1/invoices', $invoice(['contact_id' => $this->b['contact']])],
            'invoice to B\'s income account' => ['POST', '/api/v1/invoices', $invoice([], ['account_id' => $this->b['sales']])],
            'invoice with B\'s tax rate' => ['POST', '/api/v1/invoices', $invoice([], ['tax_rate_id' => $this->b['tax_rate']])],
            'invoice with B\'s item' => ['POST', '/api/v1/invoices', $invoice([], ['item_id' => $this->b['item']])],
            'edit A\'s draft onto B\'s customer' => ['PUT', "/api/v1/invoices/{$this->a['draft']}", $invoice(['contact_id' => $this->b['contact']])],
            'journal entry on B\'s accounts' => ['POST', '/api/v1/journal-entries', ['date' => '2026-11-05', 'lines' => [
                ['account_id' => $this->b['bank'], 'debit' => '10'], ['account_id' => $this->b['equity'], 'credit' => '10'],
            ]]],
            'journal entry half on B\'s account' => ['POST', '/api/v1/journal-entries', ['date' => '2026-11-05', 'lines' => [
                ['account_id' => $this->a['bank'], 'debit' => '10'], ['account_id' => $this->b['equity'], 'credit' => '10'],
            ]]],
            'journal line tagged with B\'s location' => ['POST', '/api/v1/journal-entries', ['date' => '2026-11-05', 'lines' => [
                ['account_id' => $this->a['bank'], 'debit' => '10', 'location_id' => $this->b['dimension']], ['account_id' => $this->a['equity'], 'credit' => '10'],
            ]]],
            'payment from B\'s customer' => ['POST', '/api/v1/payments-received', ['contact_id' => $this->b['contact'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-05', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10']],
            'payment into B\'s bank' => ['POST', '/api/v1/payments-received', ['contact_id' => $this->a['contact'], 'account_id' => $this->b['bank'],
                'payment_date' => '2026-11-05', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10']],
            'payment settling B\'s invoice' => ['POST', '/api/v1/payments-received', ['contact_id' => $this->a['contact'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-05', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10', 'allocations' => [['invoice_id' => $this->b['invoice'], 'amount' => '10']]]],
            'credit note for B\'s invoice' => ['POST', "/api/v1/invoices/{$this->b['invoice']}/credit-notes", ['issue_date' => '2026-11-05', 'notes' => 'Planted',
                'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $this->a['sales']]]]],
            'credit applied to B\'s invoice' => ['POST', "/api/v1/credit-notes/{$this->a['credit_note']}/apply", ['invoice_id' => $this->b['invoice'], 'amount' => '1']],
            'bill from B\'s supplier' => ['POST', '/api/v1/bills', ['contact_id' => $this->b['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB',
                'lines' => [['description' => 'Rent', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->a['rent']]]]],
            'bill to B\'s expense account' => ['POST', '/api/v1/bills', ['contact_id' => $this->a['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB',
                'lines' => [['description' => 'Rent', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->b['rent']]]]],
            'edit A\'s draft bill onto B\'s supplier' => ['PUT', "/api/v1/bills/{$this->a['bill']}", ['contact_id' => $this->b['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB',
                'lines' => [['description' => 'Rent', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->a['rent']]]]],
            'payment to B\'s supplier' => ['POST', '/api/v1/payments-made', ['contact_id' => $this->b['supplier'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-06', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10']],
            'payment out of B\'s bank' => ['POST', '/api/v1/payments-made', ['contact_id' => $this->a['supplier'], 'account_id' => $this->b['bank'],
                'payment_date' => '2026-11-06', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10']],
            'payment settling B\'s bill' => ['POST', '/api/v1/payments-made', ['contact_id' => $this->a['supplier'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-06', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '10', 'allocations' => [['bill_id' => $this->b['posted_bill'], 'amount' => '10']]]],
            'expense paid from B\'s bank' => ['POST', '/api/v1/expenses', ['contact_id' => $this->a['supplier'], 'paid_from_account_id' => $this->b['bank'],
                'payment_method' => 'cash', 'issue_date' => '2026-11-07', 'currency' => 'ETB',
                'lines' => [['description' => 'Fuel', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->a['rent']]]]],
            'expense for B\'s supplier' => ['POST', '/api/v1/expenses', ['contact_id' => $this->b['supplier'], 'paid_from_account_id' => $this->a['bank'],
                'payment_method' => 'cash', 'issue_date' => '2026-11-07', 'currency' => 'ETB',
                'lines' => [['description' => 'Fuel', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->a['rent']]]]],
            'A\'s payment allocated to B\'s invoice' => ['POST', "/api/v1/payments-received/{$this->a['payment']}/allocate", ['allocations' => [['invoice_id' => $this->b['invoice'], 'amount' => '1']]]],
            'A\'s advance allocated to B\'s bill' => ['POST', "/api/v1/payments-made/{$this->a['payment_made']}/allocate", ['allocations' => [['bill_id' => $this->b['posted_bill'], 'amount' => '1']]]],
            'refund of B\'s credit note' => ['POST', '/api/v1/refunds/customer', ['contact_id' => $this->a['contact'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-08', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '1', 'credit_id' => $this->b['credit_note']]],
            'refund to B\'s customer' => ['POST', '/api/v1/refunds/customer', ['contact_id' => $this->b['contact'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-08', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '1', 'payment_id' => $this->a['payment']]],
            'refund of B\'s advance' => ['POST', '/api/v1/refunds/supplier', ['contact_id' => $this->a['supplier'], 'account_id' => $this->a['bank'],
                'payment_date' => '2026-11-08', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '1', 'payment_id' => $this->b['payment_made']]],
            'invoice from B\'s warehouse' => ['POST', '/api/v1/invoices', $invoice(['warehouse_id' => $this->b['store']])],
            'bill bringing in B\'s stock item' => ['POST', '/api/v1/bills', ['contact_id' => $this->a['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB',
                'warehouse_id' => $this->a['store'], 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->a['rent'], 'item_id' => $this->b['stock_item']]]]],
            'supplier defaulting to B\'s account' => ['POST', '/api/v1/suppliers', ['name' => 'Planted', 'default_expense_account_id' => $this->b['rent']]],
            'account under B\'s account' => ['POST', '/api/v1/accounts', ['code' => '6101', 'name' => 'Planted', 'type' => 'expense', 'parent_id' => $this->b['rent']]],
            'account moved under B\'s account' => ['PUT', "/api/v1/accounts/{$this->a['rent']}", ['parent_id' => $this->b['rent']]],
            'transfer out of B\'s bank' => ['POST', '/api/v1/banking/transfers', ['date' => '2026-11-05', 'from_account_id' => $this->b['bank'], 'to_account_id' => $this->a['bank'], 'amount' => '10']],
            'transfer into B\'s bank' => ['POST', '/api/v1/banking/transfers', ['date' => '2026-11-05', 'from_account_id' => $this->a['bank'], 'to_account_id' => $this->b['bank'], 'amount' => '10']],
            'deposit into B\'s bank' => ['POST', '/api/v1/banking/deposits', ['date' => '2026-11-05', 'bank_account_id' => $this->b['bank'], 'receipts' => ['10']]],
            'statement into B\'s bank' => ['POST', "/api/v1/bank-accounts/{$this->b['bank']}/statements", ['csv' => "Date,Description,Amount\n05/11/2026,Planted,-10\n"]],
            'statement line matched to B\'s entry' => ['POST', "/api/v1/statement-lines/{$this->a['statement_fuel']}/match", ['journal_line_id' => $this->b['bank_line']]],
            'statement line categorised to B\'s account' => ['POST', "/api/v1/statement-lines/{$this->a['statement_line']}/categorise", ['account_id' => $this->b['rent']]],
            'general ledger of B\'s bank' => ['GET', "/api/v1/reports/general-ledger?from=2026-07-08&to=2027-07-07&account_id={$this->b['bank']}", []],
            'general ledger of B\'s location' => ['GET', "/api/v1/reports/general-ledger?from=2026-07-08&to=2027-07-07&location_id={$this->b['dimension']}", []],
            'statement line categorised for B\'s supplier' => ['POST', "/api/v1/statement-lines/{$this->a['statement_line']}/categorise", ['account_id' => $this->a['rent'], 'contact_id' => $this->b['supplier']]],
            'reconciliation of B\'s bank' => ['POST', "/api/v1/bank-accounts/{$this->b['bank']}/reconciliations", ['statement_date' => '2026-11-09', 'ending_balance' => '1']],
            'recurring journal on B\'s accounts' => ['POST', '/api/v1/recurring-journals', ['name' => 'Planted', 'memo' => 'Planted', 'frequency' => 'monthly', 'next_on' => '2026-12-01',
                'lines' => [['account_id' => $this->b['rent'], 'debit' => '10'], ['account_id' => $this->b['equity'], 'credit' => '10']]]],
            'A\'s recurring journal edited onto B\'s account' => ['PUT', "/api/v1/recurring-journals/{$this->a['recurring_journal']}", ['name' => 'Planted', 'memo' => 'Planted',
                'frequency' => 'monthly', 'next_on' => '2026-12-01', 'lines' => [['account_id' => $this->a['rent'], 'debit' => '10'], ['account_id' => $this->b['equity'], 'credit' => '10']]]],
            'audit adjustment on B\'s accounts' => ['POST', "/api/v1/fiscal-years/{$this->a['fiscal_year']}/adjustments", ['memo' => 'Planted',
                'lines' => [['account_id' => $this->b['rent'], 'debit' => '10'], ['account_id' => $this->b['equity'], 'credit' => '10']]]],
            'reclassifying B\'s ledger line' => ['POST', '/api/v1/journal-entries/reclassify', ['line_ids' => [$this->b['bank_line']], 'to_account_id' => $this->a['rent'], 'date' => '2026-11-10']],
            'reclassifying onto B\'s account' => ['POST', '/api/v1/journal-entries/reclassify', ['line_ids' => [$this->a['bank_line']], 'to_account_id' => $this->b['rent'], 'date' => '2026-11-10']],
            'tax return settled from B\'s bank' => ['POST', "/api/v1/tax-returns/{$this->a['tax_return']}/settle", ['date' => '2026-11-10', 'bank_account_id' => $this->b['bank']]],
            'invoice line on B\'s project' => ['POST', '/api/v1/invoices', $invoice([], ['project_id' => $this->b['project']])],
            'project for B\'s customer' => ['POST', '/api/v1/projects', ['code' => 'P-X', 'name' => 'Planted', 'status' => 'active', 'billing' => 'fixed_fee', 'customer_id' => $this->b['contact']]],
            'reconciliation ticking B\'s entry' => ['PUT', "/api/v1/reconciliations/{$this->a['reconciliation']}", ['cleared' => [$this->b['bank_line']]]],
        ];

        foreach ($attempts as $what => [$method, $uri, $body]) {
            $response = $this->json($method, $uri, $body, $headers);
            $this->assertContains($response->status(), [403, 404, 422], "{$what}: expected a refusal, got {$response->status()}: {$response->getContent()}");
            $this->assertNoDataOf($this->b, $response, null, $what);
        }

        $this->assertSame($before, $this->ledgerCounts(), 'A refused request must not leave rows behind.');
        foreach (['journal_lines' => 'account_id', 'document_lines' => 'account_id', 'documents' => 'contact_id'] as $table => $column) {
            $this->assertSame(0, $this->owner()->table($table)->where('company_id', $this->a['company'])
                ->whereIn($column, [$this->b['bank'], $this->b['equity'], $this->b['sales'], $this->b['contact']])->count(),
                "{$table}.{$column} of company A points at a record of company B");
        }
    }

    public function test_row_level_security_alone_hides_every_other_companys_row(): void
    {
        $tables = collect($this->owner()->select(<<<'SQL'
            SELECT c.relname AS name,
                   EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'company_id' AND NOT a.attisdropped) AS has_company
              FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relrowsecurity AND NOT c.relispartition
             ORDER BY 1
            SQL));
        $this->assertNotEmpty($tables);

        $bRows = fn (string $table, bool $hasCompany, $db) => match (true) {
            $table === 'users' => $db->table('users')->where('id', $this->b['user'])->count(),
            $table === 'user_recovery_codes' => $db->table('user_recovery_codes')->where('user_id', $this->b['user'])->count(),
            $table === 'user_email_tokens' => $db->table('user_email_tokens')->where('user_id', $this->b['user'])->count(),
            $table === 'companies' => $db->table('companies')->where('id', $this->b['company'])->count(),
            $table === 'organisations' => $db->table('organisations')->where('id', $this->b['organisation'])->count(),
            $hasCompany => $db->table($table)->where('company_id', $this->b['company'])->count(),
            default => $this->fail("{$table} has row-level security but no company_id: teach TenantIsolationTest how to find company B's rows."),
        };

        $this->owner()->table('user_recovery_codes')->insert(['user_id' => $this->b['user'], 'code_hash' => 'hash-of-a-code-of-b']);
        $this->owner()->table('user_email_tokens')->insert(['user_id' => $this->b['user'], 'purpose' => 'reset_password', 'token_hash' => 'hash-of-a-link-of-b', 'expires_at' => now()->addHour()]);

        // Fixtures are real: B has rows in the tables that matter (otherwise the check below proves nothing).
        foreach (['companies', 'organisations', 'users', 'user_recovery_codes', 'user_email_tokens', 'memberships', 'accounts', 'journal_entries', 'journal_lines', 'account_balances',
            'documents', 'document_lines', 'contacts', 'tax_rates', 'audit_log', 'einvoice_submissions', 'fiscal_years', 'bank_transactions', 'reconciliations', 'cash_counts'] as $table) {
            $row = $tables->firstWhere('name', $table);
            $this->assertNotNull($row, "{$table} has no row-level security");
            $this->assertGreaterThan(0, $bRows($table, (bool) $row->has_company, $this->owner()), "fixture: company B has no {$table} rows");
        }

        // As the application, working in company A, with no Eloquent model (so no company scope) involved.
        DatabaseTenantContext::run($this->userA->getKey(), $this->a['company'], function () use ($tables, $bRows) {
            foreach ($tables as $t) {
                $this->assertSame(0, $bRows($t->name, (bool) $t->has_company, DB::connection()), "row-level security on {$t->name} lets company A see company B");
            }
        });
    }

    public function test_the_eloquent_company_scope_alone_hides_every_other_companys_row(): void
    {
        $models = $this->modelsScopedToCompany();
        $this->assertNotEmpty($models);

        // The owner connection: row-level security does not apply, so only the scope stands between A and B.
        app(CurrentCompany::class)->set(Company::on('owner')->findOrFail($this->a['company']));

        try {
            foreach ($models as $model) {
                $unscoped = $model::on('owner')->withoutGlobalScope('company')->where('company_id', $this->b['company'])->count();
                $scoped = $model::on('owner')->where('company_id', $this->b['company'])->count();
                $this->assertSame(0, $scoped, "{$model} returns company B's rows while company A is selected");
                if (in_array($model, [\App\Domain\Sales\Models\Document::class, \App\Domain\Ledger\Models\JournalEntry::class, \App\Domain\Ledger\Models\Account::class], true)) {
                    $this->assertGreaterThan(0, $unscoped, "fixture: company B has no {$model} rows");
                }
            }
        } finally {
            app(CurrentCompany::class)->forget();
        }
    }

    // ---------------------------------------------------------------------------------------------------

    /** @param array<string, string> $ids */
    private function assertNoDataOf(array $ids, TestResponse $response, ?Route $route, string $what = ''): void
    {
        $body = (string) $response->getContent();
        $values = [...array_values($ids), ...$this->owner()->table('accounts')->where('company_id', $ids['company'])->pluck('id')->all(), 'Company B Trading'];
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $body, ($route ? "{$route->methods()[0]} {$route->uri()}" : $what) . " leaked a value of company B ({$value})");
        }
    }

    private function describe(Route $route, string $case, TestResponse $response): string
    {
        return "{$route->methods()[0]} {$route->uri()} with {$case}: got {$response->status()} " . Str::limit((string) $response->getContent(), 300);
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['journal_entries', 'journal_lines', 'documents', 'document_lines', 'payments', 'payment_allocations', 'audit_log', 'idempotency_keys', 'bank_transactions', 'reconciliations', 'reconciliation_lines', 'recurring_journals', 'tax_returns', 'projects'])
            ->mapWithKeys(fn ($t) => [$t => $this->owner()->table($t)->count()])->all();
    }

    /** @return list<class-string> every Eloquent model that uses the company scope */
    private function modelsScopedToCompany(): array
    {
        $models = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path() . DIRECTORY_SEPARATOR));
            if (class_exists($class) && ! (new \ReflectionClass($class))->isAbstract() && in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }
        sort($models);

        return $models;
    }
}
