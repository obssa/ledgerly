<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S4: every role against every company route, from one table.
 *
 * ROUTES says which permission each route asks for. For each role in config/permissions.php the test calls
 * every route as a member with that role: a role that lacks the permission gets 403, a role that has it gets
 * anything else (200, 201, or 422 for the empty body the test sends). A route missing from the table fails
 * the test, so a new route cannot ship without deciding who may use it.
 */
final class PermissionMatrixTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** "METHOD uri" => the permission its controller asks for first. */
    private const ROUTES = [
        'GET api/v1/company' => 'company.view',
        'GET api/v1/people' => 'company.view',
        'GET api/v1/members' => 'users.manage',
        'GET api/v1/subscription' => 'company.view',
        'POST api/v1/members/invitations' => 'users.manage',
        'GET api/v1/company/export' => 'company.export',
        'PUT api/v1/members/{membership}' => 'users.manage',
        'POST api/v1/members/{membership}/resend' => 'users.manage',
        'POST api/v1/members/{membership}/deactivate' => 'users.manage',
        'POST api/v1/members/{membership}/reactivate' => 'users.manage',
        'PUT api/v1/company' => 'company.manage',
        'PUT api/v1/company/period-lock' => 'periods.close',
        'GET api/v1/close-checklist' => 'periods.close',
        'GET api/v1/fiscal-years' => 'accounting.view',
        'GET api/v1/fiscal-years/{fiscalYear}/closing' => 'periods.close',
        'POST api/v1/fiscal-years/{fiscalYear}/close' => 'periods.close',
        'POST api/v1/fiscal-years/{fiscalYear}/reopen' => 'periods.reopen',
        'GET api/v1/currencies' => 'company.view',
        'PUT api/v1/currencies' => 'company.manage',
        'GET api/v1/exchange-rates' => 'company.view',
        'POST api/v1/exchange-rates' => 'accounting.rates',
        'GET api/v1/dimensions' => 'company.view',
        'POST api/v1/dimensions' => 'accounting.dimensions',
        'PUT api/v1/dimensions/{dimension}' => 'accounting.dimensions',
        'GET api/v1/numbering' => 'company.view',
        'PUT api/v1/numbering' => 'company.manage',
        'GET api/v1/opening-balances' => 'accounting.view',
        'PUT api/v1/opening-balances' => 'accounting.opening',
        'POST api/v1/opening-balances/invoices' => 'accounting.opening',
        'POST api/v1/opening-balances/bills' => 'accounting.opening',

        'GET api/v1/accounts' => 'company.view',
        'GET api/v1/chart' => 'accounting.view',
        'POST api/v1/accounts' => 'accounting.chart',
        'PUT api/v1/accounts/{account}' => 'accounting.chart',
        'GET api/v1/journal-entries' => 'accounting.view',
        'POST api/v1/journal-entries' => 'journals.create',
        'POST api/v1/journal-entries/reclassify' => 'journals.create',
        'GET api/v1/journal-entries/{entry}' => 'accounting.view',
        'GET api/v1/journal-entries/review' => 'accounting.view',
        'POST api/v1/journal-entries/{entry}/review' => 'journals.review',
        'GET api/v1/journal-requests' => 'accounting.view',
        'GET api/v1/revaluations' => 'accounting.view',
        'GET api/v1/revaluations/preview' => 'accounting.revalue',
        'POST api/v1/revaluations' => 'accounting.revalue',
        'GET api/v1/journal-requests/{journalRequest}' => 'accounting.view',
        'POST api/v1/journal-requests/{journalRequest}/approve' => 'journals.approve',
        'POST api/v1/journal-requests/{journalRequest}/reject' => 'journals.approve',
        'POST api/v1/journal-requests/{journalRequest}/withdraw' => 'journals.create',
        'GET api/v1/fiscal-years/{fiscalYear}/adjusted-trial-balance' => 'accounting.view',
        'POST api/v1/fiscal-years/{fiscalYear}/adjustments' => 'periods.reopen',
        'POST api/v1/fiscal-years/{fiscalYear}/finalise' => 'periods.reopen',
        'GET api/v1/recurring-journals' => 'accounting.view',
        'POST api/v1/recurring-journals' => 'journals.create',
        'POST api/v1/recurring-journals/post-due' => 'journals.create',
        'GET api/v1/recurring-journals/{recurringJournal}' => 'accounting.view',
        'PUT api/v1/recurring-journals/{recurringJournal}' => 'journals.create',
        'POST api/v1/recurring-journals/{recurringJournal}/pause' => 'journals.create',
        'POST api/v1/recurring-journals/{recurringJournal}/resume' => 'journals.create',
        'POST api/v1/journal-entries/{entry}/reverse' => 'journals.create',

        'GET api/v1/tax-rates' => 'company.view',
        'POST api/v1/tax-rates' => 'tax.manage',
        'GET api/v1/fixed-assets' => 'assets.view',
        'GET api/v1/fixed-assets/tax-depreciation' => 'assets.view',
        'POST api/v1/fixed-assets' => 'assets.manage',
        'GET api/v1/fixed-assets/{fixedAsset}' => 'assets.view',
        'PUT api/v1/fixed-assets/{fixedAsset}' => 'assets.manage',
        'POST api/v1/fixed-assets/{fixedAsset}/dispose' => 'assets.manage',
        'GET api/v1/depreciation/preview' => 'assets.view',
        'POST api/v1/depreciation-runs' => 'assets.depreciate',
        'GET api/v1/projects' => 'projects.view',
        'POST api/v1/projects' => 'projects.manage',
        'GET api/v1/projects/{project}' => 'projects.view',
        'PUT api/v1/projects/{project}' => 'projects.manage',
        'POST api/v1/projects/{project}/milestones' => 'projects.manage',
        'PUT api/v1/projects/{project}/milestones/{milestone}' => 'projects.manage',
        'DELETE api/v1/projects/{project}/milestones/{milestone}' => 'projects.manage',
        'POST api/v1/projects/{project}/milestones/{milestone}/bill' => 'projects.manage',
        'POST api/v1/projects/{project}/bill-time' => 'projects.manage',
        'GET api/v1/time-entries' => 'projects.time',
        'POST api/v1/time-entries' => 'projects.time',
        'PUT api/v1/time-entries/{timeEntry}' => 'projects.time',
        'DELETE api/v1/time-entries/{timeEntry}' => 'projects.time',
        'GET api/v1/tax/worksheet' => 'tax.view',
        'GET api/v1/tax/calendar' => 'tax.view',
        'GET api/v1/tax/audit-file' => 'tax.view',
        'GET api/v1/tax-returns' => 'tax.view',
        'POST api/v1/tax-returns' => 'tax.file',
        'GET api/v1/tax-returns/{taxReturn}' => 'tax.view',
        'POST api/v1/tax-returns/{taxReturn}/settle' => 'tax.file',

        'GET api/v1/customers' => 'contacts.view',
        'POST api/v1/customers' => 'contacts.create',
        'GET api/v1/customers/{customer}' => 'contacts.view',
        'GET api/v1/customers/{customer}/statement' => 'contacts.view',
        'PUT api/v1/customers/{customer}' => 'contacts.edit',

        'GET api/v1/invoices' => 'invoices.view',
        'POST api/v1/invoices' => 'invoices.create',
        'GET api/v1/invoices/{invoice}' => 'invoices.view',
        'PUT api/v1/invoices/{invoice}' => 'invoices.create',   // a draft, as here; a posted invoice asks for transactions.edit
        'POST api/v1/invoices/{invoice}/submit' => 'invoices.create',
        'POST api/v1/invoices/{invoice}/reject' => 'invoices.approve',
        'POST api/v1/invoices/{invoice}/approve' => 'invoices.approve',
        'POST api/v1/invoices/{invoice}/remind' => 'invoices.create',
        'POST api/v1/invoices/{invoice}/email' => 'invoices.create',
        'GET api/v1/invoices/{invoice}/attachments' => 'invoices.view',
        'POST api/v1/invoices/{invoice}/attachments' => 'invoices.create',
        'GET api/v1/bills/{bill}/attachments' => 'bills.view',
        'POST api/v1/bills/{bill}/attachments' => 'bills.create',
        'GET api/v1/expenses/{expense}/attachments' => 'expenses.view',
        'POST api/v1/expenses/{expense}/attachments' => 'expenses.create',
        'GET api/v1/attachments/{attachment}' => 'invoices.view',   // the fixture's file is on an invoice; a bill's needs bills.view
        'POST api/v1/attachments/{attachment}/remove' => 'invoices.create',
        'POST api/v1/imports/customers' => 'contacts.create',
        'POST api/v1/imports/suppliers' => 'contacts.create',
        'POST api/v1/imports/items' => 'inventory.items',
        'POST api/v1/imports/accounts' => 'accounting.chart',
        'POST api/v1/imports/opening-balances' => 'accounting.opening',
        'POST api/v1/imports/open-invoices' => 'accounting.opening',
        'POST api/v1/imports/open-bills' => 'accounting.opening',
        'POST api/v1/invoices/{invoice}/retention' => 'payments.receive',
        'POST api/v1/invoices/{invoice}/retention/release' => 'payments.receive',
        'POST api/v1/invoices/{invoice}/void' => 'transactions.edit',
        'GET api/v1/invoices/{invoice}/history' => 'invoices.view',
        'GET api/v1/invoices/{invoice}/pdf' => 'invoices.view',

        'POST api/v1/invoices/{invoice}/credit-notes' => 'invoices.create',
        'GET api/v1/quotes' => 'invoices.view',
        'POST api/v1/quotes' => 'invoices.create',
        'GET api/v1/quotes/{quote}' => 'invoices.view',
        'PUT api/v1/quotes/{quote}' => 'invoices.create',
        'POST api/v1/quotes/{quote}/accept' => 'invoices.create',
        'POST api/v1/quotes/{quote}/cancel' => 'invoices.create',
        'POST api/v1/quotes/{quote}/convert' => 'invoices.create',
        'POST api/v1/quotes/{quote}/email' => 'invoices.create',
        'GET api/v1/quotes/{quote}/history' => 'invoices.view',
        'GET api/v1/sales-orders' => 'invoices.view',
        'POST api/v1/sales-orders' => 'invoices.create',
        'GET api/v1/sales-orders/back-orders' => 'invoices.view',
        'GET api/v1/sales-orders/{salesOrder}' => 'invoices.view',
        'POST api/v1/sales-orders/{salesOrder}/close' => 'invoices.create',
        'PUT api/v1/sales-orders/{salesOrder}' => 'invoices.create',
        'POST api/v1/sales-orders/{salesOrder}/accept' => 'invoices.create',
        'POST api/v1/sales-orders/{salesOrder}/cancel' => 'invoices.create',
        'POST api/v1/sales-orders/{salesOrder}/convert' => 'invoices.create',
        'POST api/v1/sales-orders/{salesOrder}/email' => 'invoices.create',
        'GET api/v1/sales-orders/{salesOrder}/history' => 'invoices.view',
        'POST api/v1/quotes/{quote}/send' => 'invoices.create',
        'POST api/v1/quotes/{quote}/decline' => 'invoices.create',
        'POST api/v1/quotes/{quote}/extend' => 'invoices.create',
        'GET api/v1/credit-notes' => 'invoices.view',
        'POST api/v1/credit-notes/{creditNote}/apply' => 'payments.receive',

        'GET api/v1/suppliers' => 'contacts.view',
        'POST api/v1/suppliers' => 'contacts.create',
        'GET api/v1/suppliers/{supplier}' => 'contacts.view',
        'PUT api/v1/suppliers/{supplier}' => 'contacts.edit',

        'GET api/v1/purchase-orders' => 'bills.view',
        'POST api/v1/purchase-orders' => 'bills.create',
        'GET api/v1/purchase-orders/open' => 'bills.view',
        'GET api/v1/purchase-orders/{purchaseOrder}' => 'bills.view',
        'PUT api/v1/purchase-orders/{purchaseOrder}' => 'bills.create',
        'POST api/v1/purchase-orders/{purchaseOrder}/issue' => 'bills.create',
        'POST api/v1/purchase-orders/{purchaseOrder}/cancel' => 'bills.create',
        'POST api/v1/purchase-orders/{purchaseOrder}/bill' => 'bills.create',
        'POST api/v1/purchase-orders/{purchaseOrder}/close' => 'bills.create',
        'GET api/v1/purchase-orders/{purchaseOrder}/history' => 'bills.view',
        'POST api/v1/purchase-orders/{purchaseOrder}/receive' => 'inventory.receive',
        'GET api/v1/sales-receipts' => 'invoices.view',
        'POST api/v1/items/{item}/units' => 'inventory.items',
        'POST api/v1/items/{item}/units/{unit}/archive' => 'inventory.items',
        'POST api/v1/sales-orders/{salesOrder}/deliver' => 'inventory.deliver',
        'GET api/v1/delivery-notes' => 'invoices.view',
        'GET api/v1/delivery-notes/uninvoiced' => 'invoices.view',
        'GET api/v1/delivery-notes/{deliveryNote}' => 'invoices.view',
        'POST api/v1/delivery-notes/{deliveryNote}/void' => 'inventory.deliver',
        'POST api/v1/sales-receipts' => 'invoices.create',
        'GET api/v1/sales-receipts/{salesReceipt}' => 'invoices.view',
        'POST api/v1/sales-receipts/{salesReceipt}/void' => 'transactions.edit',
        'GET api/v1/goods-receipts' => 'bills.view',
        'GET api/v1/goods-receipts/unbilled' => 'bills.view',
        'GET api/v1/goods-receipts/{goodsReceipt}' => 'bills.view',
        'POST api/v1/goods-receipts/{goodsReceipt}/void' => 'inventory.receive',
        'GET api/v1/bills' => 'bills.view',
        'POST api/v1/bills' => 'bills.create',
        'GET api/v1/bills/{bill}' => 'bills.view',
        'PUT api/v1/bills/{bill}' => 'bills.create',   // a draft, as here; a posted bill asks for transactions.edit
        'POST api/v1/bills/{bill}/submit' => 'bills.create',
        'POST api/v1/bills/{bill}/reject' => 'bills.approve',
        'POST api/v1/bills/{bill}/approve' => 'bills.approve',
        'POST api/v1/bills/{bill}/void' => 'transactions.edit',
        'GET api/v1/bills/{bill}/history' => 'bills.view',
        'POST api/v1/bills/{bill}/credits' => 'bills.create',
        'GET api/v1/bills/{bill}/landed-costs' => 'bills.view',
        'POST api/v1/bills/{bill}/landed-costs' => 'inventory.adjust',
        'GET api/v1/supplier-credits' => 'bills.view',
        'POST api/v1/supplier-credits/{supplierCredit}/apply' => 'payments.pay',

        'GET api/v1/suppliers/{supplier}/statement' => 'contacts.view',
        'GET api/v1/expenses' => 'expenses.view',
        'POST api/v1/expenses' => 'expenses.create',
        'GET api/v1/expenses/{expense}' => 'expenses.view',
        'POST api/v1/expenses/{expense}/void' => 'transactions.edit',
        'GET api/v1/payments-made' => 'payments.view',
        'POST api/v1/payments-made' => 'payments.pay',
        'GET api/v1/payments-made/withholding/{supplier}' => 'payments.pay',
        'GET api/v1/payments-made/{paymentMade}' => 'payments.view',
        'POST api/v1/payments-made/{paymentMade}/reverse' => 'payments.reverse',
        'POST api/v1/payments-made/{paymentMade}/allocate' => 'payments.pay',
        'POST api/v1/payments-received/{payment}/allocate' => 'payments.receive',
        'GET api/v1/reports/ap-ageing' => 'reports.view',

        'GET api/v1/refunds' => 'payments.view',
        'POST api/v1/refunds/customer' => 'payments.pay',
        'POST api/v1/refunds/supplier' => 'payments.receive',
        'GET api/v1/refunds/{refund}' => 'payments.view',
        'POST api/v1/refunds/{refund}/reverse' => 'payments.reverse',

        'GET api/v1/payments-received' => 'payments.view',
        'POST api/v1/payments-received' => 'payments.receive',
        'GET api/v1/payments-received/{payment}' => 'payments.view',
        'POST api/v1/payments-received/{payment}/reverse' => 'payments.reverse',
        'POST api/v1/payments-received/{payment}/bounce' => 'payments.reverse',

        'GET api/v1/items' => 'inventory.view',
        'POST api/v1/items' => 'inventory.items',
        'GET api/v1/items/{item}' => 'inventory.view',
        'PUT api/v1/items/{item}' => 'inventory.items',
        'GET api/v1/warehouses' => 'inventory.view',
        'POST api/v1/warehouses' => 'inventory.items',
        'PUT api/v1/warehouses/{warehouse}' => 'inventory.items',
        'GET api/v1/stock' => 'inventory.view',
        'POST api/v1/stock/adjustments' => 'inventory.adjust',
        'POST api/v1/stock/issues' => 'inventory.adjust',
        'POST api/v1/stock/counts' => 'inventory.adjust',
        'POST api/v1/stock/transfers' => 'inventory.transfer',
        'GET api/v1/stock/reorder' => 'inventory.view',
        'GET api/v1/reports/inventory-valuation' => 'reports.view',
        'GET api/v1/reports/stock-movement' => 'reports.view',
        'GET api/v1/reports/stock-ageing' => 'reports.view',

        'GET api/v1/bank-accounts' => 'banking.view',
        'POST api/v1/bank-accounts' => 'banking.manage',
        'GET api/v1/bank-accounts/{bankAccount}' => 'banking.view',
        'PUT api/v1/bank-accounts/{bankAccount}' => 'banking.manage',
        'GET api/v1/bank-accounts/{bankAccount}/register' => 'banking.view',
        'GET api/v1/bank-accounts/{bankAccount}/cheques' => 'banking.view',
        'GET api/v1/post-dated-cheques' => 'banking.view',
        'POST api/v1/post-dated-cheques' => 'payments.receive',   // direction in, as the control call sends it; out asks for payments.pay
        'POST api/v1/post-dated-cheques/{cheque}/present' => 'payments.receive',   // the fixture's cheque is one received
        'POST api/v1/post-dated-cheques/{cheque}/cancel' => 'payments.receive',
        'GET api/v1/bank-accounts/{bankAccount}/cash-counts' => 'banking.view',
        'POST api/v1/bank-accounts/{bankAccount}/cash-counts' => 'banking.count',
        'GET api/v1/bank-accounts/{bankAccount}/statement-lines' => 'banking.view',
        'POST api/v1/bank-accounts/{bankAccount}/statements' => 'banking.review',
        'POST api/v1/statement-lines/{statementLine}/match' => 'banking.review',
        'POST api/v1/statement-lines/{statementLine}/categorise' => 'banking.review',
        'POST api/v1/statement-lines/{statementLine}/exclude' => 'banking.review',
        'POST api/v1/statement-lines/{statementLine}/undo' => 'banking.review',
        'GET api/v1/bank-accounts/{bankAccount}/reconciliations' => 'banking.view',
        'POST api/v1/bank-accounts/{bankAccount}/reconciliations' => 'banking.reconcile',
        'GET api/v1/reconciliations/{reconciliation}' => 'banking.view',
        'PUT api/v1/reconciliations/{reconciliation}' => 'banking.reconcile',
        'POST api/v1/reconciliations/{reconciliation}/complete' => 'banking.reconcile',
        'POST api/v1/reconciliations/{reconciliation}/undo' => 'banking.reconcile',
        'DELETE api/v1/reconciliations/{reconciliation}' => 'banking.reconcile',
        'POST api/v1/banking/transfers' => 'banking.transfer',
        'POST api/v1/banking/deposits' => 'banking.deposit',

        'GET api/v1/reports/trial-balance' => 'reports.view',
        'GET api/v1/reports/general-ledger' => 'reports.view',
        'GET api/v1/reports/balance-sheet' => 'reports.view',
        'GET api/v1/reports/bin-card' => 'reports.view',
        'GET api/v1/reports/ar-ageing' => 'reports.view',
        'GET api/v1/reports/profit-and-loss' => 'reports.view',
        'GET api/v1/reports/cash-flow' => 'reports.view',
        'GET api/v1/reports/changes-in-equity' => 'reports.view',
        'GET api/v1/reports/financial-health' => 'reports.view',
        'GET api/v1/reports/profit-by-dimension' => 'reports.view',
        'GET api/v1/reports/custom' => 'reports.view',
        'GET api/v1/reports/sales' => 'reports.view',
        'POST api/v1/reports/exports' => 'reports.view',
        'GET api/v1/audit-log' => 'audit.view',
        'GET api/v1/saved-reports' => 'reports.view',
        'POST api/v1/saved-reports' => 'reports.view',
        'GET api/v1/saved-reports/{savedReport}' => 'reports.view',
        'PUT api/v1/saved-reports/{savedReport}' => 'reports.view',
        'DELETE api/v1/saved-reports/{savedReport}' => 'reports.view',
        'GET api/v1/audit-log/verify' => 'audit.view',
        'GET api/v1/reports/purchases' => 'reports.view',
    ];

    /** @var array<string, string> */
    private array $company;

    protected function setUp(): void
    {
        parent::setUp();
        // Every company route as one user per role: more than the API's 120 a minute. Not what this test is about.
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->company = $this->companyWithRecords('owner@example.test', 'Matrix Trading');
    }

    public function test_every_company_route_has_a_row_and_every_row_is_a_route(): void
    {
        $inRouter = array_map(fn (Route $r) => self::routeName($r), $this->companyRoutes());

        $this->assertSame([], array_values(array_diff($inRouter, array_keys(self::ROUTES))),
            'New company route: add it to PermissionMatrixTest::ROUTES with the permission it asks for.');
        $this->assertSame([], array_values(array_diff(array_keys(self::ROUTES), $inRouter)),
            'PermissionMatrixTest::ROUTES lists a route that no longer exists.');
    }

    public function test_each_role_reaches_exactly_the_routes_its_permissions_allow(): void
    {
        $roles = array_keys(config('permissions.roles'));
        $this->assertSame(['owner', 'admin', 'accountant', 'bookkeeper', 'sales', 'purchasing', 'approver', 'viewer', 'custom'], $roles);
        $headers = ['X-Company-Id' => $this->company['company']];
        $routes = collect($this->companyRoutes())->keyBy(fn (Route $r) => self::routeName($r));

        $checked = 0;
        foreach ($roles as $role) {
            $this->actingAs($this->member($role), 'sanctum');

            foreach (self::ROUTES as $name => $permission) {
                $allowed = self::grants(config("permissions.roles.{$role}"), $permission);

                // Rolled back, so a role that may approve or void does not change what the next role meets.
                DB::beginTransaction();
                try {
                    $response = $this->callRoute($routes[$name], $this->company, $headers);
                } finally {
                    DB::rollBack();
                }

                if ($allowed) {
                    $this->assertNotSame(403, $response->getStatusCode(), "{$role} has {$permission} but was refused on {$name}: " . $response->getContent());
                    $this->assertLessThan(500, $response->getStatusCode(), "{$name} as {$role}: " . substr((string) $response->getContent(), 0, 300));
                } else {
                    $this->assertSame(403, $response->getStatusCode(), "{$role} lacks {$permission} but got {$response->getStatusCode()} on {$name}");
                    $this->assertStringContainsString($permission, (string) $response->json('message'));
                }
                $checked++;
            }
        }

        $this->assertSame(count($roles) * count(self::ROUTES), $checked);
    }

    public function test_a_custom_role_gets_only_the_permissions_stored_on_the_membership(): void
    {
        $member = $this->member('custom', ['invoices.view', 'reports.*']);
        $this->actingAs($member, 'sanctum');
        $headers = ['X-Company-Id' => $this->company['company']];

        $this->getJson('/api/v1/invoices', $headers)->assertOk();
        $this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31', $headers)->assertOk();
        $this->getJson('/api/v1/accounts', $headers)->assertForbidden();
        $this->postJson('/api/v1/invoices', [], $headers)->assertForbidden();
    }

    public function test_a_member_of_another_company_has_no_role_here(): void
    {
        $outsider = $this->createUser('Outsider', 'outsider@example.test');
        $this->actingAs($outsider, 'sanctum');

        $this->getJson('/api/v1/invoices', ['X-Company-Id' => $this->company['company']])->assertForbidden();
    }

    /** @param list<string>|null $permissions extra grants stored on the membership (custom role) */
    private function member(string $role, ?array $permissions = null): User
    {
        if ($role === 'owner') {
            return $this->lastUser; // the company's own owner
        }
        $user = $this->createUser(ucfirst($role), "{$role}-" . Str::random(6) . '@example.test');
        $this->owner()->table('memberships')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company['company'],
            'user_id' => $user->getKey(),
            'role' => $role,
        ] + ($permissions === null ? [] : ['permissions' => json_encode($permissions)]));

        return $user;
    }

    /**
     * The grant rule, written again here on purpose: the test must not pass just because it asks the code
     * under test what the answer is. @param list<string> $grants
     */
    private static function grants(array $grants, string $permission): bool
    {
        [$module] = explode('.', $permission);

        return in_array('*', $grants, true) || in_array($permission, $grants, true) || in_array("{$module}.*", $grants, true);
    }

    private static function routeName(Route $route): string
    {
        return collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first() . ' ' . $route->uri();
    }
}
