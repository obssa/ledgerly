<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: paying suppliers' bills, AP ageing and supplier statements. A payment made settles bills of its supplier at
 * each bill's rate, withholds tax on a certificate (never from an exempt supplier), and the ageing's total per supplier
 * is that supplier's balance on accounts payable.
 */
final class PaymentMadeTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $supplier;

    private string $bill;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Addis Packaging PLC', 'tax_id' => '0001234567'], $this->h)->assertCreated()->json('id');
        // 20,000.00 + VAT 3,000.00 = 23,000.00, due 25 Nov.
        $this->bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->supplier, 'issue_date' => '2026-11-10', 'due_date' => '2026-11-25',
            'currency' => 'ETB', 'reference' => 'AP-0457', 'lines' => [['description' => 'Coffee bags', 'quantity' => '2000', 'unit_price' => '10',
                'account_id' => $this->account('6800'), 'tax_rate_id' => $this->taxRate('VAT15')]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$this->bill}/approve", [], $this->h)->assertOk();
    }

    /** @return array<string, mixed> */
    private function payment(array $over = []): array
    {
        return ['contact_id' => $this->supplier, 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-12', 'method' => 'bank_transfer',
            'reference' => 'TT-5531', 'currency' => 'ETB', 'amount' => '22400', 'fee' => '25', 'withholding' => '600', 'withholding_certificate' => 'WHT-2027-0012',
            'allocations' => [['bill_id' => $this->bill, 'amount' => '23000']], ...$over];
    }

    public function test_paying_a_bill_with_withholding_and_a_fee_settles_it_and_posts_the_tax_owed(): void
    {
        $advice = $this->getJson("/api/v1/payments-made/withholding/{$this->supplier}", $this->h)->assertOk()
            ->assertJsonPath('applies', true)->assertJsonPath('code', 'WHT3')->assertJsonPath('services_above', '10000.00');
        $this->assertEqualsWithDelta(0.03, (float) $advice->json('rate'), 0.0000001);

        $paid = $this->postJson('/api/v1/payments-made', $this->payment(), $this->h)->assertCreated()
            ->assertJsonPath('direction', 'out')->assertJsonPath('withholding_certificate', 'WHT-2027-0012')->assertJsonPath('warnings', []);
        $this->getJson("/api/v1/bills/{$this->bill}", $this->h)->assertJsonPath('status', 'paid')->assertJsonPath('amount_due', '0.00');

        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $paid->json('journal_entry_id'))->get()->keyBy('account_id');
        $this->assertSame('23000.00', $lines[$this->account('2000')]->debit);
        $this->assertSame('22425.00', $lines[$this->c['bank']]->credit);
        $this->assertSame('600.00', $lines[$this->account('2310')]->credit, 'withholding is owed to the tax authority');
        $this->assertSame('25.00', $lines[$this->account('6600')]->debit);

        $this->getJson('/api/v1/payments-made', $this->h)->assertOk()->assertJsonPath('data.0.id', $paid->json('id'));
        $this->getJson("/api/v1/payments-made/{$paid->json('id')}", $this->h)->assertOk()->assertJsonPath('allocations.0.document.reference', 'AP-0457');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $paid->json('id'), 'action' => 'payment_made'])->count());
    }

    public function test_reversing_a_payment_reopens_the_bill(): void
    {
        $id = $this->postJson('/api/v1/payments-made', $this->payment(), $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/payments-made/{$id}/reverse", ['date' => '2026-11-13', 'reason' => 'Transfer returned by the bank'], $this->h)
            ->assertOk()->assertJsonPath('status', 'reversed');
        $this->getJson("/api/v1/bills/{$this->bill}", $this->h)->assertJsonPath('status', 'posted')->assertJsonPath('amount_due', '23000.00');
        $this->postJson("/api/v1/payments-made/{$id}/reverse", ['date' => '2026-11-13', 'reason' => 'Again'], $this->h)->assertUnprocessable();
    }

    public function test_a_dollar_bill_paid_from_the_birr_bank_books_what_the_bank_paid_and_the_loss(): void
    {
        // A 50 USD bill at 150 (7,500.00 payable), paid from the birr bank for 7,750.00 birr (rate 155): a loss of 250.00.
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->supplier, 'issue_date' => '2026-11-09', 'currency' => 'USD', 'fx_rate' => '150', 'reference' => 'US-7',
            'lines' => [['description' => 'Freight', 'quantity' => '1', 'unit_price' => '50', 'account_id' => $this->account('6200')]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $this->h)->assertOk();
        $payment = $this->postJson('/api/v1/payments-made', $this->payment(['currency' => 'USD', 'amount' => '50', 'fee' => null, 'withholding' => null, 'withholding_certificate' => null,
            'account_amount' => '7750', 'allocations' => [['bill_id' => $bill, 'amount' => '50']]]), $this->h)->assertCreated()->assertJsonPath('fx_rate', '155.000000');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $payment->json('journal_entry_id'))->get();
        $this->assertSame('7750.00', (string) $lines->firstWhere('account_id', $this->c['bank'])->credit);
        $fx = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'fx_gain_loss'])->value('id');
        $this->assertSame('250.00', (string) $lines->firstWhere('account_id', $fx)->debit);
        $this->getJson("/api/v1/bills/{$bill}", $this->h)->assertJsonPath('status', 'paid');
    }

    public function test_payment_rules(): void
    {
        $this->postJson('/api/v1/payments-made', $this->payment(['withholding_certificate' => null]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('withholding_certificate');
        $this->postJson('/api/v1/payments-made', $this->payment(['amount' => '22399.99']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->postJson('/api/v1/payments-made', $this->payment(['contact_id' => $this->c['contact']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/payments-made', $this->payment(['account_id' => $this->account('6800')]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('account_id');
        // An invoice is not a bill, and another supplier's bill is not this one's.
        $this->postJson('/api/v1/payments-made', $this->payment(['allocations' => [['bill_id' => $this->c['invoice'], 'amount' => '10']]]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.bill_id');
        $this->postJson('/api/v1/payments-made', $this->payment(['allocations' => [['bill_id' => $this->c['posted_bill'], 'amount' => '10']]]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.bill_id');

        // An exempt supplier: nothing withheld.
        $this->putJson("/api/v1/suppliers/{$this->supplier}", ['withholding_exempt' => true, 'withholding_exempt_reason' => 'WHT-EX-2027-114'], $this->h)->assertOk();
        $this->getJson("/api/v1/payments-made/withholding/{$this->supplier}", $this->h)->assertJsonPath('applies', false);
        $this->postJson('/api/v1/payments-made', $this->payment(), $this->h)->assertUnprocessable()->assertJsonValidationErrors('withholding');
        $this->postJson('/api/v1/payments-made', $this->payment(['amount' => '23000', 'fee' => '0', 'withholding' => '0', 'withholding_certificate' => null]), $this->h)->assertCreated();

        // Cash above the country's limit is warned about, not refused.
        $this->postJson('/api/v1/payments-made', $this->payment(['method' => 'cash', 'amount' => '60000', 'fee' => '0', 'withholding' => '0', 'withholding_certificate' => null, 'allocations' => []]), $this->h)
            ->assertCreated()->assertJsonPath('warnings.0.type', 'cash_limit');
    }

    public function test_a_supplier_without_a_tin_is_advised_the_higher_rate(): void
    {
        $noTin = $this->postJson('/api/v1/suppliers', ['name' => 'Market trader'], $this->h)->assertCreated()->json('id');
        $this->getJson("/api/v1/payments-made/withholding/{$noTin}", $this->h)->assertJsonPath('code', 'WHT30');
        $this->getJson("/api/v1/payments-made/withholding/{$this->c['contact']}", $this->h)->assertNotFound();   // a customer only
    }

    public function test_bookkeepers_record_bills_but_only_accountants_owners_and_admins_pay_them(): void
    {
        $this->actingAs($this->member('bookkeeper'), 'sanctum');
        $this->postJson('/api/v1/payments-made', $this->payment(), $this->h)->assertForbidden();
        $this->getJson('/api/v1/payments-made', $this->h)->assertOk();

        $this->actingAs($this->member('accountant'), 'sanctum');
        $this->postJson('/api/v1/payments-made', $this->payment(), $this->h)->assertCreated();
    }

    public function test_ap_ageing_and_the_supplier_statement_agree_with_accounts_payable(): void
    {
        $this->postJson('/api/v1/payments-made', $this->payment(['amount' => '9700', 'fee' => '0', 'withholding' => '300',
            'allocations' => [['bill_id' => $this->bill, 'amount' => '10000']]]), $this->h)->assertCreated();

        $ageing = $this->getJson('/api/v1/reports/ap-ageing?as_of=2026-12-10', $this->h)->assertOk();
        $row = collect($ageing->json('rows'))->firstWhere('contact_id', $this->supplier);
        $this->assertSame('13000.00', $row['days_1_30'], '15 days past the due date');
        $this->assertSame('13000.00', $row['total']);
        // The fixture supplier: a bill of 400.00 less an advance of 100.00, of which 10.00 came back.
        $fixture = collect($ageing->json('rows'))->firstWhere('contact_id', $this->c['supplier']);
        $this->assertSame(['400.00', '-90.00', '310.00'], [$fixture['days_31_60'], $fixture['credits'], $fixture['total']]);

        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-10', $this->h)->json('data'));
        $this->assertSame($ageing->json('totals.total'), $tb->firstWhere('account_id', $this->account('2000'))['credit'], 'the ageing total is accounts payable');

        $this->getJson("/api/v1/suppliers/{$this->supplier}/statement?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('opening', '0.00')
            ->assertJsonPath('lines.0.source_type', 'bill')->assertJsonPath('lines.0.reference', 'BILL-2027-0002')->assertJsonPath('lines.0.balance', '23000.00')
            ->assertJsonPath('lines.1.source_type', 'payment')->assertJsonPath('lines.1.debit', '10000.00')->assertJsonPath('lines.1.balance', '13000.00')
            ->assertJsonPath('closing', '13000.00');
        $this->getJson("/api/v1/suppliers/{$this->c['contact']}/statement", $this->h)->assertNotFound();
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

    private function taxRate(string $code): string
    {
        return $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
