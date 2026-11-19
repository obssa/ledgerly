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
        // Draft quote and sales order (056): a draft accepts every action, so the control calls reach the controller.
        $ids['quote'] = $this->postJson('/api/v1/quotes', $invoice(), $h)->assertCreated()->json('id');
        // A receipt attached to the draft invoice (063), kept in a folder of the test run.
        config(['attachments.root' => storage_path('framework/testing/attachments')]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $ids['attachment'] = $this->post("/api/v1/invoices/{$ids['draft']}/attachments",
            ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('receipt.png', $png)], [...$h, 'Accept' => 'application/json'])->assertCreated()->json('id');
        // A customer's post-dated cheque held for next year (062).
        $ids['post_dated_cheque'] = $this->postJson('/api/v1/post-dated-cheques', ['direction' => 'in', 'contact_id' => $ids['contact'], 'account_id' => $ids['bank'],
            'cheque_number' => '700001', 'handed_on' => '2026-11-03', 'cheque_date' => '2027-12-31', 'currency' => 'ETB', 'amount' => '100'], $h)->assertCreated()->json('id');
        $ids['sales_order'] = $this->postJson('/api/v1/sales-orders', $invoice(), $h)->assertCreated()->json('id');

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
        // A draft purchase order to the supplier (068).
        $ids['purchase_order'] = $this->postJson('/api/v1/purchase-orders', $bill('PO-REF'), $h)->assertCreated()->json('id');
        // An issued purchase order with a goods received note of no lines (069): a real note for the routes to find, that
        // moves no stock and posts nothing, so every other test sees the fixture's stock and ledger as they were.
        $issued = (string) Str::uuid7();
        $this->owner()->table('documents')->insert(['id' => $issued, 'company_id' => $company, 'type' => 'purchase_order',
            'number' => 'PO-FIXTURE-1', 'contact_id' => $ids['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'warehouse_id' => $ids['store']]);
        $this->owner()->table('documents')->where('id', $issued)->update(['status' => 'accepted']);   // issued, as the state machine allows
        // A draft cash sale (070), written as the owner: a real one for the routes to find that posts nothing, so the
        // fixture's ledger stays as every other test expects. Voiding a draft is allowed and posts nothing either.
        $ids['sales_receipt'] = (string) Str::uuid7();
        $this->owner()->table('documents')->insert(['id' => $ids['sales_receipt'], 'company_id' => $company, 'type' => 'sales_receipt',
            'contact_id' => $ids['contact'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'paid_from_account_id' => $ids['bank'], 'payment_method' => 'cash']);
        // A confirmed sales order with a delivery note of no lines (071), for the same reason as the goods received note below.
        $confirmed = (string) Str::uuid7();
        $this->owner()->table('documents')->insert(['id' => $confirmed, 'company_id' => $company, 'type' => 'sales_order',
            'number' => 'SO-FIXTURE-1', 'contact_id' => $ids['contact'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'warehouse_id' => $ids['store']]);
        $this->owner()->table('documents')->where('id', $confirmed)->update(['status' => 'accepted']);
        $ids['delivery_note'] = (string) Str::uuid7();
        $this->owner()->table('delivery_notes')->insert(['id' => $ids['delivery_note'], 'company_id' => $company, 'sales_order_id' => $confirmed,
            'number' => 'DN-FIXTURE-1', 'delivered_on' => '2026-11-06', 'warehouse_id' => $ids['store'], 'created_by' => $user->getKey()]);
        // A unit of the stock item (072), written as the owner so no audit or stock changes: a carton of 12 bags.
        $ids['item_unit'] = (string) Str::uuid7();
        $this->owner()->table('item_units')->insert(['id' => $ids['item_unit'], 'company_id' => $company, 'item_id' => $ids['stock_item'], 'name' => 'Carton', 'factor' => '12']);
        // A bank rule (076), written as the owner: bank charges to the rent account, for routes to find.
        $ids['bank_rule'] = (string) Str::uuid7();
        $this->owner()->table('bank_rules')->insert(['id' => $ids['bank_rule'], 'company_id' => $company, 'name' => 'Fixture rule', 'contains' => 'FIXTURE-NEVER-MATCHES',
            'direction' => 'out', 'target_account_id' => $ids['rent']]);
        $ids['goods_receipt'] = (string) Str::uuid7();
        $this->owner()->table('goods_receipts')->insert(['id' => $ids['goods_receipt'], 'company_id' => $company, 'purchase_order_id' => $issued,
            'number' => 'GRN-FIXTURE-1', 'received_on' => '2026-11-06', 'warehouse_id' => $ids['store'], 'created_by' => $user->getKey()]);
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

        // The bank's statement for 7 Nov: the fuel expense above, and a 2.50 SMS charge still to review.
        $this->postJson("/api/v1/bank-accounts/{$ids['bank']}/statements", ['csv' => "Date,Description,Reference,Debit,Credit,Balance\n"
            . "07/11/2026,Fuel station R-7781,FT26311,115.00,,\n07/11/2026,SMS alert charge,,2.50,,\n"], $h)->assertCreated()->assertJsonPath('imported', 2);
        $statement = $this->owner()->table('bank_transactions')->where(['company_id' => $ids['company'], 'account_id' => $ids['bank']])->pluck('id', 'amount');
        $ids['statement_line'] = $statement['-2.50'];
        $ids['statement_fuel'] = $statement['-115.00'];
        $ids['bank_line'] = $this->owner()->table('journal_lines')->where(['company_id' => $ids['company'], 'account_id' => $ids['bank'], 'credit' => '115'])->value('id');
        // The petty cash box, counted empty on 8 Nov by its keeper.
        $ids['petty_cash'] = $this->owner()->table('accounts')->where(['company_id' => $company, 'code' => '1010'])->value('id');
        $this->postJson("/api/v1/bank-accounts/{$ids['petty_cash']}/cash-counts", ['counted_on' => '2026-11-08', 'counted_by_name' => "{$name} cashier", 'counted' => '0'], $h)
            ->assertCreated()->assertJsonPath('difference', '0.00');
        $ids['fiscal_year'] = $this->owner()->table('fiscal_years')->where('company_id', $company)->orderBy('starts_on')->value('id');
        // A manual journal waiting for approval (asked by the owner), as if above the company's limit.
        $ids['journal_request'] = (string) \Illuminate\Support\Str::uuid7();
        $this->owner()->table('journal_requests')->insert(['id' => $ids['journal_request'], 'company_id' => $company, 'entry_date' => '2026-11-08',
            'memo' => 'Bonus provision', 'total' => '50.00', 'requested_by' => $this->owner()->table('memberships')->where(['company_id' => $company, 'role' => 'owner'])->value('user_id'),
            'lines' => json_encode([['account_id' => $ids['rent'], 'debit' => '50'], ['account_id' => $ids['equity'], 'credit' => '50']])]);
        // Monthly rent as a recurring journal, first due on 1 Dec (so nothing posts yet).
        $ids['recurring_journal'] = $this->postJson('/api/v1/recurring-journals', ['name' => 'Monthly rent', 'memo' => 'Rent for the month', 'frequency' => 'monthly',
            'next_on' => '2026-12-01', 'lines' => [['account_id' => $ids['rent'], 'debit' => '40'], ['account_id' => $ids['equity'], 'credit' => '40']]], $h)->assertCreated()->json('id');
        // A project for the customer, with a contract value.
        $ids['project'] = $this->postJson('/api/v1/projects', ['code' => 'P-1', 'name' => "{$name} fit-out", 'customer_id' => $ids['contact'], 'status' => 'active',
            'billing' => 'fixed_fee', 'contract_value' => '5000'], $h)->assertCreated()->json('id');
        // Three billable hours on it, not billed yet.
        $ids['time_entry'] = $this->postJson('/api/v1/time-entries', ['project_id' => $ids['project'], 'work_date' => '2026-11-09', 'hours' => '3',
            'billable' => true, 'rate' => '400', 'note' => 'Site survey'], $h)->assertCreated()->json('id');
        $ids['milestone'] = $this->postJson("/api/v1/projects/{$ids['project']}/milestones", ['name' => 'Survey report', 'amount' => '5000'], $h)
            ->assertCreated()->json('data.0.id');
        // A laptop on the fixed asset register, 36 months straight line from December.
        $byCode = fn (string $code) => $this->owner()->table('accounts')->where(['company_id' => $company, 'code' => $code])->value('id');
        $ids['fixed_asset'] = $this->postJson('/api/v1/fixed-assets', ['code' => 'IT-001', 'name' => "{$name} laptop", 'asset_account_id' => $byCode('1500'),
            'depreciation_account_id' => $byCode('1590'), 'expense_account_id' => $byCode('6700'), 'acquired_on' => '2026-11-03', 'cost' => '36000',
            'method' => 'straight_line', 'useful_life_months' => 36], $h)->assertCreated()->json('id');
        $ids['membership'] = $this->owner()->table('memberships')->where(['company_id' => $company, 'user_id' => $user->getKey()])->value('id');
        // The owner's saved report: last month's profit and loss, sent monthly.
        $ids['saved_report'] = $this->postJson('/api/v1/saved-reports', ['name' => 'Monthly P&L', 'report' => 'profit-and-loss', 'period' => 'last_month',
            'schedule' => 'monthly'], $h)->assertCreated()->json('id');
        // The VAT return for September, filed on 10 Oct (nothing was posted then, so it is all zero).
        $ids['tax_return'] = $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'filed_on' => '2026-10-10', 'reference' => "{$name} VAT Sep"], $h)->assertCreated()->json('id');
        // A reconciliation of the bank to 8 Nov in progress, its difference not yet zero.
        $ids['reconciliation'] = $this->postJson("/api/v1/bank-accounts/{$ids['bank']}/reconciliations", ['statement_date' => '2026-11-08', 'ending_balance' => '1'], $h)
            ->assertCreated()->json('id');

        return $ids;
    }

    /** @return list<Route> every route that works inside a company */
    protected function companyRoutes(): array
    {
        $routes = array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            fn (Route $r) => in_array('company', $r->gatherMiddleware(), true),
        ));
        $this->assertGreaterThanOrEqual(291, count($routes), 'company routes went missing from the router');

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
            'statementLine' => $ids['statement_line'],
            'reconciliation' => $ids['reconciliation'],
            'account' => $ids['rent'],
            'journalRequest' => $ids['journal_request'],
            'fiscalYear' => $ids['fiscal_year'],
            'recurringJournal' => $ids['recurring_journal'],
            'taxReturn' => $ids['tax_return'],
            'project' => $ids['project'],
            'timeEntry' => $ids['time_entry'],
            'milestone' => $ids['milestone'],
            'cheque' => $ids['post_dated_cheque'],
            'attachment' => $ids['attachment'],
            'quote' => $ids['quote'],
            'salesOrder' => $ids['sales_order'],
            'purchaseOrder' => $ids['purchase_order'],
            'goodsReceipt' => $ids['goods_receipt'],
            'salesReceipt' => $ids['sales_receipt'],
            'deliveryNote' => $ids['delivery_note'],
            'unit' => $ids['item_unit'],
            'bankRule' => $ids['bank_rule'],
            'fixedAsset' => $ids['fixed_asset'],
            'savedReport' => $ids['saved_report'],
            'membership' => $ids['membership'],
        ];
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params, $route) {
            $this->assertArrayHasKey($m[1], $params, "Route {$route->uri()} has parameter {{$m[1]}}: add a fixture for it in Tests\\CompanyFixtures.");

            return $params[$m[1]];
        }, $route->uri());

        $query = match ($route->uri()) {
            'api/v1/reports/trial-balance', 'api/v1/reports/balance-sheet' => ['as_of' => '2026-12-31'],
            'api/v1/reports/general-ledger' => ['from' => '2026-07-08', 'to' => '2027-07-07'],
            'api/v1/revaluations/preview' => ['as_of' => '2026-11-08'],
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
