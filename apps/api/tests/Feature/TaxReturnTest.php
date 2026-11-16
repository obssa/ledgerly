<?php

declare(strict_types=1);

namespace Tests\Feature;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 7: VAT and withholding returns, built from the ledger. November 2026 of the fixture company, plus a sale of
 * 2,000.00 and a bill of 1,000.00 both at VAT 15%: the return is filed in December, locks November's VAT, and is paid.
 */
final class TaxReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $sale;

    private string $bill;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'vat_registered' => true, 'vat_number' => '0012345678'], $this->h)->assertOk();
        $vat15 = $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => 'VAT15'])->value('id');
        $this->sale = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '4', 'unit_price' => '500', 'account_id' => $this->c['sales'], 'tax_rate_id' => $vat15]]], $this->h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$this->sale}/approve", [], $this->h)->assertOk();
        $this->bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-13', 'currency' => 'ETB', 'reference' => 'GEN-9',
            'lines' => [['description' => 'Generator service', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $this->c['rent'], 'tax_rate_id' => $vat15]]], $this->h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$this->bill}/approve", [], $this->h)->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sheet(string $kind = 'vat', string $from = '2026-11-01', string $to = '2026-11-30'): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/tax/worksheet?kind={$kind}&from={$from}&to={$to}", $this->h)->assertOk();
    }

    public function test_the_vat_worksheet_is_the_ledger_explained_document_by_document(): void
    {
        $s = $this->sheet();
        // What is filed is what the VAT accounts moved; every bit of it is explained by a document.
        $this->assertSame($s->json('sales.tax'), $s->json('output_tax'));
        $this->assertSame($s->json('purchases.tax'), $s->json('input_tax'));
        $this->assertSame(['0.00', '0.00'], [$s->json('other.output'), $s->json('other.input')]);
        $this->assertSame((string) BigDecimal::of($s->json('output_tax'))->minus($s->json('input_tax'))->toScale(2), $s->json('net'));
        $sale = collect($s->json('sales.documents'))->firstWhere('id', $this->sale);
        $this->assertSame(['2000.00', '300.00'], [$sale['taxable'], $sale['tax']]);
        $bill = collect($s->json('purchases.documents'))->firstWhere('id', $this->bill);
        $this->assertSame(['1000.00', '150.00'], [$bill['taxable'], $bill['tax']]);
        $vat = collect($s->json('sales.rates'))->firstWhere('code', 'VAT15');
        $this->assertSame('300.00', $vat['tax']);

        // A manual journal on VAT payable moves the figure, and is listed as not explained by a document.
        $entry = $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-14', 'memo' => 'VAT on a sale entered short', 'lines' => [
            ['account_id' => $this->c['equity'], 'debit' => '10'],
            ['account_id' => $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'vat_payable'])->value('id'), 'credit' => '10'],
        ]], $this->h)->assertCreated()->json('id');
        $after = $this->sheet();
        $this->assertSame('10.00', $after->json('other.output'));
        $this->assertSame([$entry], collect($after->json('other.entries'))->pluck('id')->all());
        $this->assertSame((string) BigDecimal::of($s->json('output_tax'))->plus(10)->toScale(2), $after->json('output_tax'));
    }

    public function test_a_filed_return_locks_its_period_for_vat_and_is_settled_once(): void
    {
        $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'filed_on' => '2026-11-17'], $this->h)
            ->assertUnprocessable();   // November has not ended yet
        Carbon::setTestNow(Carbon::parse('2026-12-05 09:00:00', 'UTC'));
        $s = $this->sheet();
        $filed = $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'filed_on' => '2026-12-04',
            'reference' => 'MOR-2026-11'], $this->h)->assertCreated()
            ->assertJsonPath('output_tax', $s->json('output_tax'))->assertJsonPath('net', $s->json('net'))->assertJsonPath('can.settle', true);
        $id = $filed->json('id');
        $this->assertSame($s->json('sales.tax'), $filed->json('figures.sales.tax'));
        $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-11-15', 'period_end' => '2026-11-30', 'filed_on' => '2026-12-04'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('period_start');

        // November's VAT is closed; other accounts and later dates are not.
        $vatPayable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'vat_payable'])->value('id');
        $journal = fn (string $date, string $credit) => $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => 'Late correction', 'lines' => [
            ['account_id' => $this->c['equity'], 'debit' => '10'], ['account_id' => $credit, 'credit' => '10']]], $this->h);
        $this->assertStringContainsString('VAT return for 2026-11-01 to 2026-11-30', (string) $journal('2026-11-20', $vatPayable)->assertUnprocessable()->json('message'));
        $journal('2026-11-20', $this->c['rent'])->assertCreated();
        $journal('2026-12-02', $vatPayable)->assertCreated();

        // Settled after the period, from the bank: VAT payable against VAT receivable and the bank.
        $this->postJson("/api/v1/tax-returns/{$id}/settle", ['date' => '2026-11-30', 'bank_account_id' => $this->c['bank']], $this->h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/tax-returns/{$id}/settle", ['date' => '2026-12-05'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
        $settled = $this->postJson("/api/v1/tax-returns/{$id}/settle", ['date' => '2026-12-05', 'bank_account_id' => $this->c['bank']], $this->h)->assertOk()
            ->assertJsonPath('settled_on', '2026-12-05')->assertJsonPath('can.settle', false);
        $lines = collect($this->getJson("/api/v1/journal-entries/{$settled->json('settlement.id')}", $this->h)->assertOk()->assertJsonPath('source_type', 'tax_settlement')->json('lines'));
        $this->assertSame($s->json('output_tax'), $lines->firstWhere('account_id', $vatPayable)['debit']);
        $this->assertSame($s->json('net'), $lines->firstWhere('account_id', $this->c['bank'])['credit']);
        $this->postJson("/api/v1/tax-returns/{$id}/settle", ['date' => '2026-12-05', 'bank_account_id' => $this->c['bank']], $this->h)->assertUnprocessable();

        // December's worksheet leaves the settlement out: it is November's tax being paid, not December's.
        $december = $this->sheet('vat', '2026-12-01', '2026-12-31');
        $this->assertSame([], collect($december->json('other.entries'))->where('source_type', 'tax_settlement')->values()->all());
        $this->assertSame(['tax_return_filed', 'tax_return_settled'], $this->owner()->table('audit_log')->where('subject_id', $id)->orderBy('id')->pluck('action')->all());
        $this->assertCount(2, $this->getJson('/api/v1/tax-returns', $this->h)->assertOk()->json('data'));   // with the fixture's September return
    }

    public function test_the_withholding_return_lists_what_was_withheld_from_suppliers(): void
    {
        $this->postJson('/api/v1/payments-made', ['contact_id' => $this->c['supplier'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-14',
            'method' => 'bank_transfer', 'currency' => 'ETB', 'amount' => '1130', 'withholding' => '20', 'reference' => 'TT-55', 'withholding_certificate' => 'WHT-0091',
            'allocations' => [['bill_id' => $this->bill, 'amount' => '1150']]], $this->h)->assertCreated();
        $s = $this->sheet('withholding');
        $this->assertSame(['20.00', '0.00', '20.00'], [$s->json('output_tax'), $s->json('input_tax'), $s->json('net')]);
        $this->assertSame([['1150.00', '20.00', 'TT-55']], collect($s->json('withheld.payments'))->map(fn ($p) => [$p['gross'], $p['withheld'], $p['reference']])->all());
        $this->assertSame('0.00', $s->json('other.output'));
    }
}
