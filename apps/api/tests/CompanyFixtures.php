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
     * rate, a foreign currency with a rate, a location, a payment of 50.00 on the posted invoice, a draft credit note on it, a customer, a supplier with a posted and a draft bill an advance of 100.00 (10.00 of it refunded) and an expense of 115.00, an item and a warehouse. @return array<string, string>
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

        $ids['dimension'] = $this->postJson('/api/v1/dimensions', ['kind' => 'location', 'code' => 'HQ', 'name' => "{$name} head office"], $h)
            ->assertCreated()->json('id');
        $this->putJson('/api/v1/currencies', ['currency' => 'USD', 'enabled' => true], $h)->assertOk();
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => '2026-11-02', 'rate' => '134.523333'], $h)->assertCreated();

        $invoice = fn () => ['contact_id' => $ids['contact'], 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '2', 'unit_price' => '500', 'account_id' => $ids['sales'], 'tax_rate_id' => $ids['tax_rate']]]];
        $ids['invoice'] = $this->postJson('/api/v1/invoices', $invoice(), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$ids['invoice']}/approve", [], $h)->assertOk();
        $ids['payment'] = $this->postJson('/api/v1/payments-received', [
            'contact_id' => $ids['contact'], 'account_id' => $ids['bank'], 'payment_date' => '2026-11-03', 'method' => 'cash',
            'currency' => 'ETB', 'amount' => '50', 'allocations' => [['invoice_id' => $ids['invoice'], 'amount' => '50']],
        ], $h)->assertCreated()->json('id');
        $ids['credit_note'] = $this->postJson("/api/v1/invoices/{$ids['invoice']}/credit-notes", ['issue_date' => '2026-11-04', 'notes' => 'One bag returned',
            'lines' => [['description' => 'Coffee returned', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $ids['sales']]]], $h)->assertCreated()->json('id');
        $ids['draft'] = $this->postJson('/api/v1/invoices', $invoice(), $h)->assertCreated()->json('id');

        // A stock item with 20 on hand at 50.00 (opening stock, 2 Nov) in the company's main store.
        $ids['store'] = $this->postJson('/api/v1/warehouses', ['name' => 'Main store'], $h)->assertCreated()->json('id');
        $ids['stock_item'] = $this->postJson('/api/v1/items', ['name' => 'Roasted coffee 1 kg', 'sku' => 'RC-1K', 'type' => 'inventory', 'unit' => 'bag', 'sale_price' => '1150'], $h)
            ->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $ids['stock_item'], 'warehouse_id' => $ids['store'], 'date' => '2026-11-02',
            'quantity' => '20', 'unit_cost' => '50', 'opening' => true, 'reason' => 'Opening count'], $h)->assertCreated();

        // A supplier with a posted bill (400.00 of rent, 5 Nov) and a draft bill.
        $ids['supplier'] = $this->postJson('/api/v1/suppliers', ['name' => "{$name} supplier"], $h)->assertCreated()->json('id');
        $ids['rent'] = $this->owner()->table('accounts')->where(['company_id' => $company, 'code' => '6100'])->value('id');
        $bill = fn (string $ref) => ['contact_id' => $ids['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'reference' => $ref,
            'lines' => [['description' => 'Office rent', 'quantity' => '1', 'unit_price' => '400', 'account_id' => $ids['rent']]]];
        $ids['posted_bill'] = $this->postJson('/api/v1/bills', $bill('R-100'), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$ids['posted_bill']}/approve", [], $h)->assertOk();
        $ids['bill'] = $this->postJson('/api/v1/bills', $bill('R-101'), $h)->assertCreated()->json('id');
        $ids['supplier_credit'] = $this->postJson("/api/v1/bills/{$ids['posted_bill']}/credits", ['issue_date' => '2026-11-06', 'reference' => 'RC-1',
            'notes' => 'Rent overcharged', 'lines' => [['description' => 'Rent correction', 'quantity' => '1', 'unit_price' => '40', 'account_id' => $ids['rent']]]], $h)
            ->assertCreated()->json('id');
        // An expense paid from the bank: 115.00 of fuel (7 Nov).
        $ids['expense'] = $this->postJson('/api/v1/expenses', ['contact_id' => $ids['supplier'], 'paid_from_account_id' => $ids['bank'],
            'payment_method' => 'cash', 'reference' => 'R-7781', 'issue_date' => '2026-11-07', 'currency' => 'ETB',
            'lines' => [['description' => 'Fuel', 'quantity' => '1', 'unit_price' => '115', 'account_id' => $ids['rent']]]], $h)->assertCreated()->json('id');
        // An advance of 100.00 to the supplier (6 Nov), not allocated to a bill.
        $ids['payment_made'] = $this->postJson('/api/v1/payments-made', ['contact_id' => $ids['supplier'], 'account_id' => $ids['bank'],
            'payment_date' => '2026-11-06', 'method' => 'bank_transfer', 'currency' => 'ETB', 'amount' => '100'], $h)->assertCreated()->json('id');
        // The supplier gives 10.00 of the advance back (8 Nov), so 90.00 of it is left.
        $ids['refund'] = $this->postJson('/api/v1/refunds/supplier', ['contact_id' => $ids['supplier'], 'account_id' => $ids['bank'], 'payment_date' => '2026-11-08',
            'method' => 'bank_transfer', 'currency' => 'ETB', 'amount' => '10', 'payment_id' => $ids['payment_made']], $h)->assertCreated()->json('id');

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
        $this->assertGreaterThanOrEqual(99, count($routes), 'company routes went missing from the router');

        return $routes;
    }

    /** @param array<string, string> $ids */
    protected function callRoute(Route $route, array $ids, array $headers): TestResponse
    {
        $params = [
            'invoice' => $ids['draft'],     // a draft accepts every invoice action, so the control call reaches the controller
            'entry' => $ids['entry'],
            'dimension' => $ids['dimension'],
            'customer' => $ids['contact'],
            'payment' => $ids['payment'],
            'creditNote' => $ids['credit_note'],
            'supplier' => $ids['supplier'],
            'bill' => $ids['bill'],         // a draft, like the invoice
            'paymentMade' => $ids['payment_made'],
            'expense' => $ids['expense'],
            'supplierCredit' => $ids['supplier_credit'],
            'refund' => $ids['refund'],
            'item' => $ids['stock_item'],
            'warehouse' => $ids['store'],
            'bankAccount' => $ids['bank'],
        ];
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params, $route) {
            $this->assertArrayHasKey($m[1], $params, "Route {$route->uri()} has parameter {{$m[1]}}: add a fixture for it in Tests\\CompanyFixtures.");

            return $params[$m[1]];
        }, $route->uri());

        $query = match ($route->uri()) {
            'api/v1/reports/trial-balance', 'api/v1/reports/balance-sheet' => ['as_of' => '2026-12-31'],
            'api/v1/reports/profit-and-loss' => ['from' => '2026-07-08', 'to' => '2027-07-07'],
            'api/v1/exchange-rates' => ['currency' => 'USD', 'from' => '2026-07-08', 'to' => '2027-07-07'],
            'api/v1/payments-received/{payment}/reverse', 'api/v1/payments-made/{paymentMade}/reverse', 'api/v1/refunds/{refund}/reverse' => [],
            'api/v1/reports/bin-card' => ['item_id' => $ids['item'], 'warehouse_id' => $ids['warehouse'], 'from' => '2026-07-08', 'to' => '2027-07-07'],
            default => [],
        };
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $this->json($method, '/' . $uri . ($query ? '?' . http_build_query($query) : ''), [], $headers);
    }
}
