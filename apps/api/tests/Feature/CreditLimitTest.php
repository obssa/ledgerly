<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2, credit limit: a warning on saving and approving an invoice that takes the customer over their limit;
 * never a block (product rule). The fixture customer already owes 1,000.00 on a posted invoice (1,050.00 less 50.00 paid).
 */
final class CreditLimitTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->putJson("/api/v1/customers/{$this->c['contact']}", ['credit_limit' => '1500'], $this->h)->assertOk();
    }

    public function test_an_invoice_over_the_limit_saves_and_posts_with_a_warning(): void
    {
        // 1,000.00 owed + 600.00 = 1,600.00 > 1,500.00
        $id = $this->draft('600')->assertCreated()
            ->assertJsonPath('warnings.0.type', 'CreditLimit')
            ->assertJsonPath('warnings.0.balance_after', '1600.00')
            ->assertJsonPath('warnings.0.credit_limit', '1500.00')
            ->json('id');

        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('warnings.0.balance_after', '1600.00');
    }

    public function test_within_the_limit_there_is_no_warning_and_the_screen_learns_what_it_may_do(): void
    {
        $id = $this->draft('400')->assertCreated()->assertJsonPath('warnings', [])->json('id');   // 1,400.00

        $this->getJson("/api/v1/invoices/{$id}", $this->h)->assertOk()
            ->assertJsonPath('can', ['edit' => true, 'approve' => true, 'void' => true, 'credit' => true, 'apply' => true, 'refund' => true])
            ->assertJsonPath('contact.name', 'Abyssinia Coffee customer');
    }

    private function draft(string $price): TestResponse
    {
        return $this->postJson('/api/v1/invoices', [
            'contact_id' => $this->c['contact'], 'issue_date' => '2026-11-03', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $this->c['sales']]],
        ], $this->h);
    }
}
