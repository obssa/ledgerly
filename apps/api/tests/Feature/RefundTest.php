<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Refunds give credit back: to a customer from a credit note's credit left or an overpayment (Dr receivable, Cr bank),
 * from a supplier on a supplier credit or an advance (Dr bank, Cr payable). Never more than the credit holds.
 */
final class RefundTest extends TestCase
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

    /** @return array<string, mixed> */
    private function refund(array $over): array
    {
        return ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-12', 'method' => 'bank_transfer',
            'reference' => 'TT-RF-1', 'currency' => 'ETB', 'amount' => '60', ...$over];
    }

    public function test_a_customers_credit_note_credit_is_paid_back(): void
    {
        // The fixture's draft credit note of 100.00, posted; the invoice owes 1,000.00, so all of it is applied there.
        // A second credit note on a paid-up position leaves credit to refund: pay the invoice off first.
        $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-05',
            'method' => 'cash', 'currency' => 'ETB', 'amount' => '1000', 'allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '1000']]], $this->h)->assertCreated();
        $this->postJson("/api/v1/invoices/{$this->c['credit_note']}/approve", [], $this->h)->assertOk()->assertJsonPath('amount_due', '100.00');
        $ar = $this->balance('1200');

        $refund = $this->postJson('/api/v1/refunds/customer', $this->refund(['credit_id' => $this->c['credit_note']]), $this->h)->assertCreated()
            ->assertJsonPath('kind', 'refund')->assertJsonPath('direction', 'out')->assertJsonPath('allocations.0.document.amount_due', '40.00');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $refund->json('journal_entry_id'))->get()->keyBy('account_id');
        $this->assertSame('60.00', $lines[$this->account('1200')]->debit);
        $this->assertSame('60.00', $lines[$this->c['bank']]->credit);
        $this->assertSame(bcadd($ar, '60.00', 2), $this->balance('1200'), 'the customer\'s credit shrinks by what was paid back');

        $this->postJson('/api/v1/refunds/customer', $this->refund(['credit_id' => $this->c['credit_note'], 'amount' => '40.01']), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->getJson('/api/v1/refunds', $this->h)->assertOk()->assertJsonPath('data.0.id', $refund->json('id'));
        $this->getJson('/api/v1/payments-made', $this->h)->assertOk()->assertJsonMissing(['id' => $refund->json('id')]);   // not a payment to a supplier
        $this->getJson("/api/v1/payments-made/{$refund->json('id')}", $this->h)->assertNotFound();

        // Reversed: the credit is back.
        $this->postJson("/api/v1/refunds/{$refund->json('id')}/reverse", ['date' => '2026-11-13', 'reason' => 'Transfer returned'], $this->h)->assertOk();
        $this->getJson("/api/v1/invoices/{$this->c['credit_note']}", $this->h)->assertJsonPath('amount_due', '100.00');
    }

    public function test_an_overpayment_is_paid_back_and_the_payment_shows_less_unused(): void
    {
        $payment = $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-10',
            'method' => 'cash', 'currency' => 'ETB', 'amount' => '200'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/refunds/customer', $this->refund(['payment_id' => $payment, 'amount' => '200.01']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $refund = $this->postJson('/api/v1/refunds/customer', $this->refund(['payment_id' => $payment, 'amount' => '150']), $this->h)->assertCreated()->json('id');
        $this->getJson("/api/v1/payments-received/{$payment}", $this->h)->assertJsonPath('unallocated', '50.00');

        // The payment cannot be reversed while a refund of it stands.
        $this->postJson("/api/v1/payments-received/{$payment}/reverse", ['date' => '2026-11-12', 'reason' => 'Bounced'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/refunds/{$refund}/reverse", ['date' => '2026-11-12', 'reason' => 'Wrong account'], $this->h)->assertOk();
        $this->getJson("/api/v1/payments-received/{$payment}", $this->h)->assertJsonPath('unallocated', '200.00');
        $this->postJson("/api/v1/payments-received/{$payment}/reverse", ['date' => '2026-11-12', 'reason' => 'Bounced'], $this->h)->assertOk();
    }

    public function test_a_supplier_pays_back_an_advance(): void
    {
        // The fixture already took 10.00 of the advance back; 90.00 is left.
        $this->getJson("/api/v1/refunds/{$this->c['refund']}", $this->h)->assertOk()->assertJsonPath('direction', 'in')->assertJsonPath('refunds_payment_id', $this->c['payment_made']);
        $body = ['contact_id' => $this->c['supplier'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-12', 'method' => 'cash', 'currency' => 'ETB'];
        $this->postJson('/api/v1/refunds/supplier', [...$body, 'amount' => '90.01', 'payment_id' => $this->c['payment_made']], $this->h)->assertUnprocessable();
        $entry = $this->postJson('/api/v1/refunds/supplier', [...$body, 'amount' => '90', 'payment_id' => $this->c['payment_made']], $this->h)->assertCreated()->json('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get()->keyBy('account_id');
        $this->assertSame('90.00', $lines[$this->c['bank']]->debit);
        $this->assertSame('90.00', $lines[$this->account('2000')]->credit);
        $this->getJson("/api/v1/payments-made/{$this->c['payment_made']}", $this->h)->assertJsonPath('unallocated', '0.00');
    }

    public function test_refund_rules(): void
    {
        $this->postJson('/api/v1/refunds/customer', $this->refund([]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('credit_id');
        $this->postJson('/api/v1/refunds/customer', $this->refund(['credit_id' => $this->c['credit_note'], 'payment_id' => $this->c['payment']]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('credit_id');
        // A draft credit note has no credit to give back; a supplier's credit is not a customer's.
        $this->postJson('/api/v1/refunds/customer', $this->refund(['credit_id' => $this->c['credit_note']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('credit_id');
        $this->postJson('/api/v1/refunds/customer', $this->refund(['credit_id' => $this->c['supplier_credit']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('credit_id');
        $this->postJson('/api/v1/refunds/customer', $this->refund(['contact_id' => $this->c['supplier'], 'payment_id' => $this->c['payment_made']]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('contact_id');

        $this->actingAs($this->member('bookkeeper'), 'sanctum');
        $this->postJson('/api/v1/refunds/customer', $this->refund(['payment_id' => $this->c['payment']]), $this->h)->assertForbidden();   // money out
    }

    private function balance(string $code): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->account($code)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
