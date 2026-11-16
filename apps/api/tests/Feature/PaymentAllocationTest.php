<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * What a payment did not allocate is used later: a customer's credit against a new invoice, an advance against a new
 * bill. The receivable or payable balance does not move (the money is already there); in a foreign currency the gap
 * between the payment's rate and the document's is booked, and reversed with the payment.
 */
final class PaymentAllocationTest extends TestCase
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

    public function test_a_customers_credit_settles_a_later_invoice(): void
    {
        $payment = $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'],
            'payment_date' => '2026-11-10', 'method' => 'cash', 'currency' => 'ETB', 'amount' => '300'], $this->h)->assertCreated()->json('id');
        $this->getJson("/api/v1/payments-received/{$payment}", $this->h)->assertJsonPath('unallocated', '300.00')->assertJsonPath('can.allocate', true);
        $ar = $this->balance('1200');

        $this->postJson("/api/v1/payments-received/{$payment}/allocate", ['allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '300']]], $this->h)
            ->assertOk()->assertJsonPath('allocations.0.document.amount_due', '700.00');
        $this->getJson("/api/v1/payments-received/{$payment}", $this->h)->assertJsonPath('unallocated', '0.00');
        $this->assertSame($ar, $this->balance('1200'), 'the receivable does not move: the money was already there');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $payment, 'action' => 'payment_allocated'])->count());

        // Nothing is left to allocate, and a reversal puts the invoice back.
        $this->postJson("/api/v1/payments-received/{$payment}/allocate", ['allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '0.01']]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->postJson("/api/v1/payments-received/{$payment}/reverse", ['date' => '2026-11-11', 'reason' => 'Cheque bounced'], $this->h)->assertOk();
        $this->getJson("/api/v1/invoices/{$this->c['invoice']}", $this->h)->assertJsonPath('amount_due', '1000.00');
        $this->postJson("/api/v1/payments-received/{$payment}/allocate", ['allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '1']]], $this->h)
            ->assertUnprocessable();
    }

    public function test_an_advance_to_a_supplier_settles_a_later_bill(): void
    {
        // The fixture's advance of 100.00 (10.00 refunded) against its posted rent bill of 400.00.
        $this->getJson("/api/v1/payments-made/{$this->c['payment_made']}", $this->h)->assertJsonPath('unallocated', '90.00');
        $this->postJson("/api/v1/payments-made/{$this->c['payment_made']}/allocate", ['allocations' => [['bill_id' => $this->c['posted_bill'], 'amount' => '90.01']]], $this->h)
            ->assertUnprocessable();
        $this->postJson("/api/v1/payments-made/{$this->c['payment_made']}/allocate", ['allocations' => [['bill_id' => $this->c['posted_bill'], 'amount' => '90']]], $this->h)
            ->assertOk();
        $this->getJson("/api/v1/bills/{$this->c['posted_bill']}", $this->h)->assertJsonPath('status', 'part_paid')->assertJsonPath('amount_due', '310.00');

        // Not a bill, another supplier's bill, more than is left: refused.
        $this->postJson("/api/v1/payments-made/{$this->c['payment_made']}/allocate", ['allocations' => [['bill_id' => $this->c['invoice'], 'amount' => '1']]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.bill_id');
        $other = $this->postJson('/api/v1/suppliers', ['name' => 'Other supplier'], $this->h)->json('id');
        $otherBill = $this->postJson('/api/v1/bills', ['contact_id' => $other, 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $this->c['rent']]]], $this->h)->json('id');
        $this->postJson("/api/v1/bills/{$otherBill}/approve", [], $this->h)->assertOk();
        $this->postJson("/api/v1/payments-made/{$this->c['payment_made']}/allocate", ['allocations' => [['bill_id' => $otherBill, 'amount' => '1']]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.bill_id');
    }

    public function test_in_a_foreign_currency_the_rate_difference_is_booked_and_reversed_with_the_payment(): void
    {
        $usdCash = $this->account('1010');
        $this->owner()->table('accounts')->where('id', $usdCash)->update(['currency' => 'USD']);
        // 100 USD received at 135 with nothing to settle; an invoice of 100 USD at 130 comes later.
        $payment = $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $usdCash, 'payment_date' => '2026-11-10',
            'method' => 'cash', 'currency' => 'USD', 'fx_rate' => '135', 'amount' => '100'], $this->h)->assertCreated()->json('id');
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-11', 'currency' => 'USD', 'fx_rate' => '130',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->c['sales']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $this->h)->assertOk();

        $this->postJson("/api/v1/payments-received/{$payment}/allocate", ['allocations' => [['invoice_id' => $invoice, 'amount' => '100']]], $this->h)->assertOk();
        $fx = $this->owner()->table('journal_entries')->where(['source_type' => 'payment_allocation', 'source_id' => $payment])->first();
        $this->assertNotNull($fx, 'an exchange difference entry');
        $gain = $this->owner()->table('journal_lines')->where(['journal_entry_id' => $fx->id, 'account_id' => $this->account('4950')])->first();
        $this->assertSame('500.00', $gain->credit, 'received 13,500.00 for an invoice of 13,000.00');
        $this->assertSame('0.00', $this->contactBalance('1200', $this->c['contact'], $invoice), 'the invoice is cleared in the base currency too');

        $this->postJson("/api/v1/payments-received/{$payment}/reverse", ['date' => '2026-11-12', 'reason' => 'Notes were counterfeit'], $this->h)->assertOk();
        $this->assertSame('reversed', $this->owner()->table('journal_entries')->where('id', $fx->id)->value('status'), 'the exchange difference goes with the payment');
    }

    private function balance(string $code): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->account($code)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::text AS b')->value('b');
    }

    /** The receivable for this customer from the USD invoice, its payment and the exchange entry (base currency). */
    private function contactBalance(string $code, string $contact, string $invoice): string
    {
        $entries = $this->owner()->table('journal_entries')->where('company_id', $this->c['company'])
            ->where(fn ($q) => $q->where('source_id', $invoice)->orWhere('currency', 'USD'))->pluck('id');

        return (string) $this->owner()->table('journal_lines')->whereIn('journal_entry_id', $entries)
            ->where(['account_id' => $this->account($code), 'contact_id' => $contact])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
