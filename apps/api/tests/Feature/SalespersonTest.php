<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 2/10: the salesperson on an invoice and its credit notes, and sales by salesperson. */
final class SalespersonTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->me = $this->lastUser;
    }

    private function member(string $name, string $role = 'sales', string $status = 'active'): User
    {
        $user = $this->createUser($name, Str::slug($name) . '@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role,
            'status' => $status]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->me, 'sanctum');

        return $user;
    }

    private function invoice(?string $salesperson, string $price = '1000', string $date = '2026-11-10'): string
    {
        $id = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => $date, 'currency' => 'ETB', 'salesperson_id' => $salesperson,
            'lines' => [['description' => 'Roasting', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $this->c['sales']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();

        return $id;
    }

    public function test_an_invoice_names_its_salesperson_and_a_credit_note_takes_it_back_from_them(): void
    {
        // No sandbox clearance, so the posted invoice can still be edited (a registered one is credited and reissued).
        config(['einvoicing.force_sandbox' => false]);
        $this->app->forgetInstance(\App\Domain\Compliance\ConnectorRegistry::class);
        $selam = $this->member('Selam Bekele');
        $this->member('Invited Person', 'sales', 'invited');
        $people = collect($this->getJson('/api/v1/people', $this->h)->assertOk()->json('data'));
        $this->assertSame(['Abyssinia Coffee', 'Selam Bekele'], $people->pluck('name')->sort()->values()->all(), 'active members only, by name');
        $this->assertSame(['id', 'name'], array_keys($people->first()), 'names only');

        $invoice = $this->invoice($selam->getKey());
        $this->getJson("/api/v1/invoices/{$invoice}", $this->h)->assertJsonPath('salesperson.name', 'Selam Bekele');
        $credit = $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => '2026-11-11', 'notes' => 'Returned',
            'lines' => [['description' => 'Roasting returned', 'quantity' => '1', 'unit_price' => '250', 'account_id' => $this->c['sales']]]], $this->h)->assertCreated();
        $this->assertSame($selam->getKey(), $credit->json('salesperson_id'));

        // A posted invoice moves to another salesperson with a reason; the change is in its history.
        $this->putJson("/api/v1/invoices/{$invoice}", ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'salesperson_id' => $this->me->getKey(), 'reason' => 'Owner made this sale',
            'lines' => [['description' => 'Roasting', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $this->c['sales']]]], $this->h)->assertOk()
            ->assertJsonPath('salesperson_id', $this->me->getKey());
        $this->assertSame($selam->getKey(), $this->getJson("/api/v1/invoices/{$invoice}/history", $this->h)->json('versions.0.snapshot.salesperson_id'));
    }

    public function test_only_an_active_member_is_a_salesperson_and_past_sales_stay_theirs(): void
    {
        $abebe = $this->member('Abebe Girma');
        $invoice = $this->invoice($abebe->getKey());
        $other = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($this->me, 'sanctum');
        $body = fn (?string $who) => ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'salesperson_id' => $who,
            'lines' => [['description' => 'Roasting', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->c['sales']]]];
        $this->postJson('/api/v1/invoices', $body($this->lastUser->getKey()), $this->h)->assertUnprocessable()->assertJsonValidationErrors('salesperson_id');
        $this->postJson('/api/v1/invoices', $body((string) Str::uuid()), $this->h)->assertUnprocessable()->assertJsonValidationErrors('salesperson_id');
        $this->postJson('/api/v1/invoices', $body('not-a-uuid'), $this->h)->assertUnprocessable()->assertJsonValidationErrors('salesperson_id');
        $this->assertNotContains($this->lastUser->name, collect($this->getJson('/api/v1/people', $this->h)->json('data'))->pluck('name'));

        // Abebe leaves: no new sale is theirs, but their invoice keeps them, and a credit note on it takes the sale back from them.
        $this->owner()->table('memberships')->where(['company_id' => $this->c['company'], 'user_id' => $abebe->getKey()])->update(['status' => 'deactivated']);
        $this->postJson('/api/v1/invoices', $body($abebe->getKey()), $this->h)->assertUnprocessable()->assertJsonValidationErrors('salesperson_id');
        $credit = $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'Damaged',
            'lines' => [['description' => 'Roasting', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->c['sales']]]], $this->h)->assertCreated();
        $this->assertSame($abebe->getKey(), $credit->json('salesperson_id'));
        $draft = $this->postJson('/api/v1/invoices', $body(null), $this->h)->assertCreated()->json();
        $this->putJson("/api/v1/invoices/{$draft['id']}", [...$body(null), 'notes' => 'No salesperson sent'] + ['lock_version' => $draft['lock_version']], $this->h)->assertOk()
            ->assertJsonPath('salesperson_id', null);
        $this->assertNull($this->owner()->table('documents')->where('company_id', $other['company'])->whereNotNull('salesperson_id')->value('id'));
    }

    public function test_sales_by_salesperson_take_credit_notes_off(): void
    {
        $selam = $this->member('Selam Bekele');
        $invoice = $this->invoice($selam->getKey(), '1000');
        $this->invoice($selam->getKey(), '500');
        $this->invoice($this->me->getKey(), '300');
        $credit = $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => '2026-11-11', 'notes' => 'Returned',
            'lines' => [['description' => 'Roasting returned', 'quantity' => '1', 'unit_price' => '250', 'account_id' => $this->c['sales']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$credit}/approve", [], $this->h)->assertOk();

        $r = $this->getJson('/api/v1/reports/sales?group=salesperson&from=2026-11-10&to=2026-11-30', $this->h)->assertOk()->assertJsonPath('group', 'salesperson');
        $rows = collect($r->json('rows'))->keyBy('label');
        $this->assertSame(['1250.00', 3], [$rows['Selam Bekele']['amount'], $rows['Selam Bekele']['documents']], '1,000 + 500 less the 250 credited');
        $this->assertSame('300.00', $rows['Abyssinia Coffee']['amount']);
        $this->assertSame($selam->getKey(), $rows['Selam Bekele']['key']);
        $byCustomer = $this->getJson('/api/v1/reports/sales?from=2026-11-10&to=2026-11-30', $this->h)->json('totals.amount');
        $this->assertSame($byCustomer, $r->json('totals.amount'), 'the same sales, grouped another way');
        $this->getJson('/api/v1/reports/sales?group=salesperson&from=2026-11-01&to=2026-11-09', $this->h)->assertOk()->assertJsonPath('rows.0.label', 'No salesperson');
        $this->getJson('/api/v1/reports/purchases?group=salesperson', $this->h)->assertUnprocessable()->assertJsonValidationErrors('group');
    }
}
