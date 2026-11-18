<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 9a, construction: retention held on an invoice and released later (050). */
final class RetentionTest extends TestCase
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
    }

    /** What an account holds for the company, from the ledger (debit minus credit). */
    private function balance(string $accountId): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $accountId])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
    }

    public function test_retention_held_is_owed_later_and_released_is_owed_again_with_the_ledger_in_step(): void
    {
        $inv = $this->c['invoice'];
        $before = $this->getJson("/api/v1/invoices/{$inv}", $this->h)->assertOk();
        $total = (string) $before->json('total');
        $owed = (string) $before->json('amount_due');
        $ar = (string) $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $arBefore = $this->balance($ar);

        $this->postJson("/api/v1/invoices/{$inv}/retention", ['amount' => bcadd($owed, '1', 2), 'held_on' => '2026-11-05'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/invoices/{$this->c['draft']}/retention", ['percent' => '10', 'held_on' => '2026-11-05'], $this->h)->assertUnprocessable();

        // 10% of the invoice total, held: it is owed later, not now.
        $tenth = bcadd(bcdiv(bcmul($total, '10', 6), '100', 6), '0.005', 2);
        $held = $this->postJson("/api/v1/invoices/{$inv}/retention", ['percent' => '10', 'held_on' => '2026-11-05'], $this->h)->assertCreated()
            ->assertJsonPath('retentions.0.amount', $tenth)->assertJsonPath('retentions.0.released_on', null)->assertJsonPath('can.retain', true);
        $this->assertSame(bcsub($owed, $tenth, 2), (string) $held->json('amount_due'));
        $retention = (string) $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'retention_receivable'])->value('id');
        $this->assertNotSame('', $retention, 'the retention receivable account is made when first needed');
        $this->assertSame($tenth, $this->balance($retention));
        $this->assertSame(bcsub($arBefore, $tenth, 2), $this->balance($ar));

        // The customer pays what is owed now: the invoice is paid, with the retention still to come.
        $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-06',
            'method' => 'cash', 'currency' => 'ETB', 'amount' => bcsub($owed, $tenth, 2), 'allocations' => [['invoice_id' => $inv, 'amount' => bcsub($owed, $tenth, 2)]]], $this->h)
            ->assertCreated();
        $this->getJson("/api/v1/invoices/{$inv}", $this->h)->assertJsonPath('status', 'paid')->assertJsonPath('amount_due', '0.00');

        // Not voided or edited while retention is held.
        $this->postJson("/api/v1/invoices/{$inv}/void", ['reason' => 'Mistake'], $this->h)->assertStatus(422);

        // Released: owed again, and the retention account is empty.
        $this->postJson("/api/v1/invoices/{$inv}/retention/release", ['released_on' => '2026-11-04'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('released_on');
        $released = $this->postJson("/api/v1/invoices/{$inv}/retention/release", ['released_on' => '2026-11-15'], $this->h)->assertOk()
            ->assertJsonPath('retentions.0.released_on', '2026-11-15')->assertJsonPath('status', 'part_paid');
        $this->assertSame($tenth, (string) $released->json('amount_due'));
        $this->assertSame('0.00', $this->balance($retention));
        $this->postJson("/api/v1/invoices/{$inv}/retention/release", ['released_on' => '2026-11-15'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('retention');

        $this->assertSame(['retention_held', 'retention_released'],
            $this->owner()->table('audit_log')->where(['subject_id' => $inv])->whereIn('action', ['retention_held', 'retention_released'])->orderBy('id')->pluck('action')->all());
    }

    public function test_a_role_without_payments_does_not_hold_retention_and_a_credit_note_has_none(): void
    {
        $this->postJson("/api/v1/invoices/{$this->c['credit_note']}/retention", ['percent' => '5', 'held_on' => '2026-11-05'], $this->h)->assertNotFound();
        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $this->c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $this->postJson("/api/v1/invoices/{$this->c['invoice']}/retention", ['percent' => '5', 'held_on' => '2026-11-05'], $this->h)->assertForbidden();
    }
}
