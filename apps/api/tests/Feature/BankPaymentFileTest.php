<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: the bank payment file. The bank transfers to suppliers recorded from the bank on 15 Nov, one row each, with
 * each supplier's account; a supplier without one is listed apart; one whose details just changed is flagged; a cash
 * payment and a reversed transfer are not in it. A cell a spreadsheet would run is made text. Only who pays suppliers
 * makes it, and it is audited. Another company's file has none of it.
 */
final class BankPaymentFileTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_days_bank_transfers_make_one_file_for_the_bank(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $file = fn (array $q = []) => $this->getJson('/api/v1/payments-made/bank-file?' . http_build_query(['date' => '2026-11-15', 'account_id' => $c['bank'], ...$q]), $h);

        $this->putJson("/api/v1/suppliers/{$c['supplier']}", ['payout' => ['bank_name' => 'Commercial Bank of Ethiopia', 'account_name' => '=Addis Rentals',
            'account_number' => '1000123456789']], $h)->assertOk();
        $other = $this->postJson('/api/v1/suppliers', ['name' => 'Packaging House'], $h)->assertCreated()->json('id');
        $pay = fn (string $supplier, string $method, string $amount) => $this->postJson('/api/v1/payments-made', ['contact_id' => $supplier, 'account_id' => $c['bank'],
            'payment_date' => '2026-11-15', 'method' => $method, 'currency' => 'ETB', 'amount' => $amount, 'reference' => 'Nov rent'], $h)->assertCreated()->json('id');
        $rent = $pay($c['supplier'], 'bank_transfer', '400');
        $pay($other, 'bank_transfer', '250');
        $pay($c['supplier'], 'cash', '50');

        $f = $file(['download' => 1])->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.payment_id', $rent)->assertJsonPath('rows.0.account_number', '1000123456789')
            ->assertJsonPath('missing.0.supplier', 'Packaging House')->assertJsonPath('recently_changed', 1)->assertJsonPath('totals.ETB', '400.00')
            ->assertJsonPath('filename', 'bank-payments-2026-11-15.csv')->json();
        $this->assertSame("Beneficiary,Bank,Account number,Amount,Currency,Reference,Payment\r\n'=Addis Rentals,Commercial Bank of Ethiopia,1000123456789,400.00,ETB,Nov rent,{$rent}\r\n", $f['csv']);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'bank_file_downloaded'])->count());   // the download, not each look
        $file(['date' => '2026-11-16'])->assertOk()->assertJsonCount(0, 'rows');
        $file(['method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->getJson('/api/v1/payments-made/bank-file', $h)->assertOk();   // today, from every money account

        $this->postJson("/api/v1/payments-made/{$rent}/reverse", ['date' => '2026-11-16', 'reason' => 'Sent twice'], $h)->assertOk();
        $file()->assertOk()->assertJsonCount(0, 'rows');

        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $file()->assertForbidden();

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirs = $this->getJson('/api/v1/payments-made/bank-file?date=2026-11-15', ['X-Company-Id' => $b['company']])->assertOk()->json();
        $this->assertSame([[], []], [$theirs['rows'], $theirs['missing']]);
        $this->getJson('/api/v1/payments-made/bank-file?' . http_build_query(['date' => '2026-11-15', 'account_id' => $c['bank']]), ['X-Company-Id' => $b['company']])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }
}
