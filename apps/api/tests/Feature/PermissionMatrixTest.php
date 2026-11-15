<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
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
        'PUT api/v1/company' => 'company.manage',
        'PUT api/v1/company/period-lock' => 'periods.close',

        'GET api/v1/accounts' => 'accounting.view',
        'GET api/v1/journal-entries' => 'accounting.view',
        'POST api/v1/journal-entries' => 'journals.create',
        'POST api/v1/journal-entries/{entry}/reverse' => 'journals.create',

        'GET api/v1/tax-rates' => 'accounting.view',
        'POST api/v1/tax-rates' => 'tax.manage',

        'GET api/v1/invoices' => 'invoices.view',
        'POST api/v1/invoices' => 'invoices.create',
        'GET api/v1/invoices/{invoice}' => 'invoices.view',
        'PUT api/v1/invoices/{invoice}' => 'invoices.create',   // a draft, as here; a posted invoice asks for transactions.edit
        'POST api/v1/invoices/{invoice}/submit' => 'invoices.create',
        'POST api/v1/invoices/{invoice}/reject' => 'invoices.approve',
        'POST api/v1/invoices/{invoice}/approve' => 'invoices.approve',
        'POST api/v1/invoices/{invoice}/void' => 'transactions.edit',
        'GET api/v1/invoices/{invoice}/history' => 'invoices.view',

        'POST api/v1/banking/transfers' => 'banking.transfer',
        'POST api/v1/banking/deposits' => 'banking.deposit',

        'GET api/v1/reports/trial-balance' => 'reports.view',
        'GET api/v1/reports/balance-sheet' => 'reports.view',
        'GET api/v1/reports/bin-card' => 'reports.view',
        'GET api/v1/reports/profit-and-loss' => 'reports.view',
    ];

    /** @var array<string, string> */
    private array $company;

    protected function setUp(): void
    {
        parent::setUp();
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
                    $this->assertNotSame(403, $response->status(), "{$role} has {$permission} but was refused on {$name}: " . $response->getContent());
                    $this->assertLessThan(500, $response->status(), "{$name} as {$role}: " . substr((string) $response->getContent(), 0, 300));
                } else {
                    $this->assertSame(403, $response->status(), "{$role} lacks {$permission} but got {$response->status()} on {$name}");
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
