<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A real company to test against, and a way to call every route that works inside a company. Shared by the
 * tests that must cover every such route: TenantIsolationTest (S1) and PermissionMatrixTest (S4).
 */
trait CompanyFixtures
{
    protected ?User $lastUser = null;

    /**
     * A company set up through the API by its own owner, with a posted invoice, a draft, a manual entry, a tax
     * rate, a foreign currency with a rate, a customer, an item and a warehouse. @return array<string, string>
     */
    protected function companyWithRecords(string $email, string $name): array
    {
        $user = $this->createUser($name, $email);
        $this->lastUser = $user;
        $this->actingAs($user, 'sanctum');

        $company = $this->postJson('/api/v1/companies', ['legal_name' => $name, 'country_code' => 'ET', 'fiscal_year_start_month' => 7])
            ->assertCreated()->json('id');
        $h = ['X-Company-Id' => $company];
        $account = fn (string $key) => $this->owner()->table('accounts')->where(['company_id' => $company, 'system_key' => $key])->value('id');

        $ids = [
            'user' => $user->getKey(), 'company' => $company,
            'organisation' => $this->owner()->table('companies')->where('id', $company)->value('organisation_id'),
            'sales' => $account('sales'), 'bank' => $account('bank'), 'equity' => $account('opening_balance_equity'),
            'contact' => (string) Str::uuid(), 'item' => (string) Str::uuid(), 'warehouse' => (string) Str::uuid(),
        ];
        $this->owner()->table('contacts')->insert(['id' => $ids['contact'], 'company_id' => $company, 'name' => "{$name} customer", 'is_customer' => true]);
        $this->owner()->table('items')->insert(['id' => $ids['item'], 'company_id' => $company, 'name' => 'Coffee', 'type' => 'service']);
        $this->owner()->table('warehouses')->insert(['id' => $ids['warehouse'], 'company_id' => $company, 'name' => 'Main']);

        $ids['tax_rate'] = $this->postJson('/api/v1/tax-rates', ['code' => 'LOCAL5', 'name' => 'Local levy', 'kind' => 'other', 'rate' => '0.05', 'effective_from' => '2026-07-08'], $h)
            ->assertCreated()->json('id');

        $this->putJson('/api/v1/currencies', ['currency' => 'USD', 'enabled' => true], $h)->assertOk();
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => '2026-11-02', 'rate' => '134.523333'], $h)->assertCreated();

        $invoice = fn () => ['contact_id' => $ids['contact'], 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '2', 'unit_price' => '500', 'account_id' => $ids['sales'], 'tax_rate_id' => $ids['tax_rate']]]];
        $ids['invoice'] = $this->postJson('/api/v1/invoices', $invoice(), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$ids['invoice']}/approve", [], $h)->assertOk();
        $ids['draft'] = $this->postJson('/api/v1/invoices', $invoice(), $h)->assertCreated()->json('id');

        $ids['entry'] = $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-04', 'memo' => 'Opening cash', 'lines' => [
            ['account_id' => $ids['bank'], 'debit' => '1000'], ['account_id' => $ids['equity'], 'credit' => '1000'],
        ]], $h)->assertCreated()->json('id');

        return $ids;
    }

    /** @return list<Route> every route that works inside a company */
    protected function companyRoutes(): array
    {
        $routes = array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            fn (Route $r) => in_array('company', $r->gatherMiddleware(), true),
        ));
        $this->assertGreaterThanOrEqual(32, count($routes), 'company routes went missing from the router');

        return $routes;
    }

    /** @param array<string, string> $ids */
    protected function callRoute(Route $route, array $ids, array $headers): TestResponse
    {
        $params = [
            'invoice' => $ids['draft'],     // a draft accepts every invoice action, so the control call reaches the controller
            'entry' => $ids['entry'],
        ];
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params, $route) {
            $this->assertArrayHasKey($m[1], $params, "Route {$route->uri()} has parameter {{$m[1]}}: add a fixture for it in Tests\\CompanyFixtures.");

            return $params[$m[1]];
        }, $route->uri());

        $query = match ($route->uri()) {
            'api/v1/reports/trial-balance', 'api/v1/reports/balance-sheet' => ['as_of' => '2026-12-31'],
            'api/v1/reports/profit-and-loss' => ['from' => '2026-07-08', 'to' => '2027-07-07'],
            'api/v1/exchange-rates' => ['currency' => 'USD', 'from' => '2026-07-08', 'to' => '2027-07-07'],
            'api/v1/reports/bin-card' => ['item_id' => $ids['item'], 'warehouse_id' => $ids['warehouse'], 'from' => '2026-07-08', 'to' => '2027-07-07'],
            default => [],
        };
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $this->json($method, '/' . $uri . ($query ? '?' . http_build_query($query) : ''), [], $headers);
    }
}
