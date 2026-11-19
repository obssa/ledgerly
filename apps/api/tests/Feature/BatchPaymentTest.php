<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: batch payments. One run from the bank pays two suppliers: Addis Packaging's 23,000.00 bill in full and 500.00
 * of its 1,150.00 one, 600.00 withheld (22,900.00 leaves the bank), and Bole Printing's 2,300.00 bill. One payment per
 * supplier; all are made or none, so a bill already paid, another company's bill or tax withheld from a supplier not in
 * the run stops it and nothing is paid. Cheques are written one by one.
 */
final class BatchPaymentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_suppliers_are_paid_in_one_run_or_not_at_all(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $expense = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '6800'])->value('id');
        $bill = function (string $supplier, string $amount) use ($h, $expense) {
            $id = $this->postJson('/api/v1/bills', ['contact_id' => $supplier, 'issue_date' => '2026-11-10', 'due_date' => '2026-11-25', 'currency' => 'ETB',
                'lines' => [['description' => 'Supplies', 'quantity' => '1', 'unit_price' => $amount, 'account_id' => $expense]]], $h)->assertCreated()->json('id');
            $this->postJson("/api/v1/bills/{$id}/approve", [], $h)->assertOk();

            return $id;
        };
        $packaging = $this->postJson('/api/v1/suppliers', ['name' => 'Addis Packaging PLC', 'tax_id' => '0001234567'], $h)->assertCreated()->json('id');
        $printing = $this->postJson('/api/v1/suppliers', ['name' => 'Bole Printing', 'tax_id' => '0007654321'], $h)->assertCreated()->json('id');
        [$a1, $a2, $p1] = [$bill($packaging, '23000'), $bill($packaging, '1150'), $bill($printing, '2300')];
        $bank = fn () => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $c['bank']])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $state = fn (string $id) => (fn ($d) => [(string) $d->status, (string) $d->amount_due])($this->owner()->table('documents')->where('id', $id)->first());
        $run = fn (array $over = []) => ['account_id' => $c['bank'], 'payment_date' => '2026-11-12', 'method' => 'bank_transfer', 'reference' => 'RUN-11', 'currency' => 'ETB',
            'bills' => [['bill_id' => $a1], ['bill_id' => $a2, 'amount' => '500'], ['bill_id' => $p1]],
            'withholding' => [['contact_id' => $packaging, 'amount' => '600', 'certificate' => 'WHT-2027-0031']], ...$over];
        $before = $bank();

        // All or none.
        $this->postJson('/api/v1/payments-made/batch', $run(['method' => 'cheque']), $h)->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->postJson('/api/v1/payments-made/batch', $run(['bills' => [['bill_id' => $a1], ['bill_id' => $b['bill']]]]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills.1.bill_id');
        $this->postJson('/api/v1/payments-made/batch', $run(['withholding' => [['contact_id' => $printing, 'amount' => '600']]]), $h)->assertUnprocessable()->assertJsonValidationErrors('withholding.0.certificate');
        $this->postJson('/api/v1/payments-made/batch', $run(['bills' => [['bill_id' => $p1]]]), $h)->assertUnprocessable()->assertJsonValidationErrors('withholding');
        $this->postJson('/api/v1/payments-made/batch', $run(['bills' => [['bill_id' => $a1], ['bill_id' => $p1, 'amount' => '9999']]]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills');
        $this->assertSame([$before, ['posted', '23000.00'], ['posted', '2300.00']], [$bank(), $state($a1), $state($p1)]);

        $made = $this->postJson('/api/v1/payments-made/batch', $run(), $h)->assertCreated()->assertJsonCount(2, 'payments')->json();
        $this->assertSame([['Addis Packaging PLC', '22900.00', '600.00', 2], ['Bole Printing', '2300.00', '0.00', 1]],
            array_map(fn ($p) => [$p['supplier'], $p['amount'], $p['withholding'], $p['bills']], $made['payments']));
        $this->assertSame(['paid', '0.00'], $state($a1));
        $this->assertSame(['part_paid', '650.00'], $state($a2));
        $this->assertSame(['paid', '0.00'], $state($p1));
        $this->assertSame(bcsub($before, '25200.00', 2), $bank());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'batch_paid', 'subject_id' => $made['batch_id']])->count());

        // A bill already paid stops the next run; nothing more leaves the bank.
        $this->postJson('/api/v1/payments-made/batch', $run(['bills' => [['bill_id' => $a2], ['bill_id' => $p1]], 'withholding' => []]), $h)->assertUnprocessable();
        $this->assertSame(['part_paid', '650.00'], $state($a2));
        $this->assertSame(bcsub($before, '25200.00', 2), $bank());
    }
}
