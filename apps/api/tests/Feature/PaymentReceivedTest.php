<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2, payments received: settling invoices (with tax withheld and a bank fee), several invoices at once, a
 * customer credit, foreign currency at each invoice's own rate, the refusals, and reversal. Roles:
 * PermissionMatrixTest. Other companies: TenantIsolationTest. Database rules: T20, T43.
 */
final class PaymentReceivedTest extends TestCase
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
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // posted invoice 1,050.00, 50.00 paid
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    public function test_a_payment_with_tax_withheld_and_a_bank_fee_settles_the_invoice(): void
    {
        // 1,000.00 still due: the customer withholds 30.00 and pays 970.00; the bank keeps 5.00.
        $id = $this->pay(['amount' => '970', 'withholding' => '30', 'fee' => '5', 'allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '1000']]])
            ->assertCreated()->assertJsonPath('allocations.0.document.status', 'paid')->json('id');

        $this->assertSame('0.00', (string) $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('amount_due'));
        $entry = $this->owner()->table('payments')->where('id', $id)->value('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->orderBy('line_no')
            ->get(['account_id', 'debit', 'credit'])->map(fn ($l) => [$this->key($l->account_id), (string) $l->debit, (string) $l->credit])->all();
        $this->assertSame([
            ['bank', '965.00', '0.00'], ['bank_charges', '5.00', '0.00'], ['withholding_receivable', '30.00', '0.00'], ['accounts_receivable', '0.00', '1000.00'],
        ], $lines);

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'payment_received', 'subject_id' => $id])->sole();
        $this->assertEquals([['invoice' => 'INV-2027-0001', 'amount' => '1000.00']], json_decode($audit->after, true)['allocations']);   // jsonb: keys, not their order
        $this->getJson("/api/v1/customers/{$this->c['contact']}", $this->h)->assertJsonPath('open_balance', '0.00');
    }

    public function test_one_payment_settles_several_invoices_and_the_rest_stays_as_the_customers_credit(): void
    {
        $second = $this->postedInvoice('300');
        $this->pay(['amount' => '1500', 'allocations' => [
            ['invoice_id' => $this->c['invoice'], 'amount' => '1000'], ['invoice_id' => $second, 'amount' => '300'],
        ]])->assertCreated()->assertJsonCount(2, 'allocations');

        // 200.00 was not allocated: the receivable account shows the customer 200.00 in credit.
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31', $this->h)->json('data'));
        $ar = $tb->firstWhere('account_id', $this->accountId('accounts_receivable'));
        $this->assertSame('200.00', $ar['credit']);
    }

    public function test_a_foreign_currency_payment_clears_the_invoice_at_its_own_rate_and_books_the_gain(): void
    {
        // 100 USD invoiced at the fixture rate 134.523333 (13,452.33 receivable), paid when the rate is 140.
        $usd = $this->postedInvoice('100', 'USD');
        $this->pay(['currency' => 'USD', 'account_id' => $this->usdBank(), 'amount' => '100', 'fx_rate' => '140', 'allocations' => [['invoice_id' => $usd, 'amount' => '100']]])
            ->assertCreated();

        $fx = $this->owner()->table('journal_lines')->where('account_id', $this->accountId('fx_gain_loss'))->sole();
        $this->assertSame(['0.00', '547.67'], [(string) $fx->debit, (string) $fx->credit]);   // 14,000.00 - 13,452.33
    }

    public function test_what_a_payment_may_not_do(): void
    {
        $draft = $this->c['draft'];
        $this->pay(['allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '1000.01']]])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.amount');
        $this->pay(['amount' => '100', 'allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '101']]])->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->pay(['allocations' => [['invoice_id' => $draft, 'amount' => '10']]])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.invoice_id');
        $this->pay(['account_id' => $this->accountId('sales')])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->pay(['account_id' => $this->usdBank()])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->pay(['amount' => '10', 'fee' => '11'])->assertUnprocessable()->assertJsonValidationErrors('fee');
        $this->pay(['payment_date' => now()->addDay()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('payment_date');

        $this->putJson('/api/v1/company/period-lock', ['books_closed_until' => '2026-11-10'], $this->h)->assertOk();
        $before = $this->owner()->table('payments')->count();
        $this->pay(['payment_date' => '2026-11-09'])->assertUnprocessable();
        $this->assertSame($before, $this->owner()->table('payments')->count(), 'a refused payment leaves nothing behind');
    }

    public function test_reversing_a_payment_reopens_the_invoice_and_reverses_the_entry(): void
    {
        $id = $this->pay(['amount' => '1000', 'allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '1000']]])->json('id');

        $this->postJson("/api/v1/payments-received/{$id}/reverse", ['date' => '2026-11-12'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/payments-received/{$id}/reverse", ['date' => '2026-11-12', 'reason' => 'Cheque returned unpaid'], $this->h)
            ->assertOk()->assertJsonPath('status', 'reversed')->assertJsonCount(0, 'allocations');

        $this->assertSame(['part_paid', '1000.00'], [
            $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('status'),
            (string) $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('amount_due'),
        ]);
        $entry = $this->owner()->table('payments')->where('id', $id)->value('journal_entry_id');
        $this->assertSame('reversed', $this->owner()->table('journal_entries')->where('id', $entry)->value('status'));
        $this->assertSame('Cheque returned unpaid', $this->owner()->table('audit_log')->where(['action' => 'payment_reversed', 'subject_id' => $id])->value('reason'));

        $this->postJson("/api/v1/payments-received/{$id}/reverse", ['date' => '2026-11-12', 'reason' => 'Again'], $this->h)->assertUnprocessable();
    }

    /** @param array<string, mixed> $over */
    private function pay(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/payments-received', [
            'contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-10', 'method' => 'bank_transfer',
            'reference' => 'TRF-1', 'currency' => 'ETB', 'amount' => '100', ...$over,
        ], $this->h);
    }

    private function postedInvoice(string $price, string $currency = 'ETB'): string
    {
        $id = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-04', 'currency' => $currency,
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $this->c['sales']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();

        return $id;
    }

    /** A bank account kept in USD. */
    private function usdBank(): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $this->owner()->table('accounts')->insert(['id' => $id, 'company_id' => $this->c['company'], 'code' => '1015', 'name' => 'Bank USD', 'type' => 'asset', 'subtype' => 'bank', 'currency' => 'USD']);

        return $id;
    }

    private function accountId(string $key): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => $key])->value('id');
    }

    private function key(string $accountId): ?string
    {
        return $this->owner()->table('accounts')->where('id', $accountId)->value('system_key');
    }
}
