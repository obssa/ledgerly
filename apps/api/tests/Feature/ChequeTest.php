<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 5: cheques written from the bank's cheque book, cheques received, and cheques that bounce. */
final class ChequeTest extends TestCase
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

    private function payByCheque(?string $number, string $amount = '50'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/payments-made', ['contact_id' => $this->c['supplier'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-09',
            'method' => 'cheque', 'cheque_number' => $number, 'currency' => 'ETB', 'amount' => $amount,
            'allocations' => [['bill_id' => $this->c['posted_bill'], 'amount' => $amount]]], $this->h);
    }

    public function test_a_cheque_written_takes_the_next_number_of_the_cheque_book_once(): void
    {
        $this->payByCheque(null)->assertUnprocessable()->assertJsonValidationErrors('cheque_number');
        $this->payByCheque('00 101')->assertUnprocessable()->assertJsonValidationErrors('cheque_number');
        $cheque = $this->payByCheque('000101')->assertCreated()->assertJsonPath('cheque_number', '000101');
        $this->payByCheque('000101', '5')->assertUnprocessable()->assertJsonValidationErrors('cheque_number');

        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/cheques", $this->h)->assertOk()
            ->assertJsonPath('direction', 'out')->assertJsonPath('next_number', '000102')
            ->assertJsonPath('data.0.number', '000101')->assertJsonPath('data.0.status', 'issued')->assertJsonPath('data.0.amount', '50.00');

        // Cancelled: the payment is reversed, the bill owes again, and the number stays used.
        $this->postJson("/api/v1/payments-made/{$cheque->json('id')}/reverse", ['date' => '2026-11-10', 'reason' => 'Cheque spoilt'], $this->h)->assertOk();
        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/cheques", $this->h)->assertOk()->assertJsonPath('data.0.status', 'cancelled');
        $this->payByCheque('000101', '5')->assertUnprocessable()->assertJsonValidationErrors('cheque_number');
        $this->payByCheque('000102', '5')->assertCreated();
    }

    public function test_a_cheque_received_clears_on_the_statement_and_bounces_back_onto_the_invoice(): void
    {
        $due = $this->getJson("/api/v1/invoices/{$this->c['invoice']}", $this->h)->assertOk()->json('amount_due');
        $cheque = $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-09',
            'method' => 'cheque', 'cheque_number' => 'CQ-55', 'currency' => 'ETB', 'amount' => '10',
            'allocations' => [['invoice_id' => $this->c['invoice'], 'amount' => '10']]], $this->h)->assertCreated()->assertJsonPath('cheque_number', 'CQ-55');
        $id = $cheque->json('id');
        $register = fn () => $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/cheques?direction=in", $this->h)->assertOk()->json('data.0');
        $this->assertSame(['CQ-55', 'received'], [$register()['number'], $register()['status']]);

        // The bank shows the deposit: matched, the cheque is cleared.
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/statements", ['csv' => "Date,Description,Amount\n09/11/2026,CHEQUE DEPOSIT CQ-55,10.00\n"], $this->h)->assertCreated();
        $line = $this->owner()->table('bank_transactions')->where(['company_id' => $this->c['company'], 'amount' => '10.00'])->value('id');
        $journalLine = $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->c['bank'],
            'journal_entry_id' => $this->owner()->table('payments')->where('id', $id)->value('journal_entry_id')])->value('id');
        $this->postJson("/api/v1/statement-lines/{$line}/match", ['journal_line_id' => $journalLine], $this->h)->assertOk();
        $this->assertSame('cleared', $register()['status']);

        // It bounces three days later, with a 25.00 charge: the invoice owes again, the deposit's match stands.
        $this->postJson("/api/v1/payments-received/{$id}/bounce", ['date' => '2026-11-08'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/payments-received/{$id}/bounce", ['date' => '2026-11-12', 'fee' => '25'], $this->h)->assertOk()
            ->assertJsonPath('status', 'reversed')->assertJsonPath('bounced_on', '2026-11-12');
        $this->assertSame(['bounced', '2026-11-12'], [$register()['status'], $register()['bounced_on']]);
        $this->getJson("/api/v1/invoices/{$this->c['invoice']}", $this->h)->assertOk()->assertJsonPath('amount_due', $due);
        $this->assertSame('matched', $this->owner()->table('bank_transactions')->where('id', $line)->value('status'));
        $charges = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'bank_charges'])->value('id');
        $this->assertSame(1, $this->owner()->table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where(['e.source_type' => 'bounced_cheque', 'e.source_id' => $id, 'l.account_id' => $charges, 'l.debit' => '25'])->count());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'cheque_bounced'])->count());
        $this->postJson("/api/v1/payments-received/{$id}/bounce", ['date' => '2026-11-12'], $this->h)->assertUnprocessable();

        // Only cheques bounce; a number on a bank transfer is not kept.
        $this->postJson("/api/v1/payments-received/{$this->c['payment']}/bounce", ['date' => '2026-11-12'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-09',
            'method' => 'bank_transfer', 'cheque_number' => '777', 'currency' => 'ETB', 'amount' => '1'], $this->h)->assertCreated()->assertJsonPath('cheque_number', null);
    }
}
