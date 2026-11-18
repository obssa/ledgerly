<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: money moved between two foreign currencies (dollars to euros). One entry in each currency at its own rate,
 * joined through "Money between currencies", which they leave at nothing; the difference in birr is the exchange gain
 * or loss; each account moves by its own currency's amount.
 */
final class ForeignTransferTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_dollars_to_euros_post_one_entry_per_currency_and_book_the_difference(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // dollars enabled, 134.523333 on 2 Nov
        $h = ['X-Company-Id' => $c['company']];
        $this->putJson('/api/v1/currencies', ['currency' => 'EUR', 'enabled' => true], $h)->assertOk();
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => '2026-11-10', 'rate' => '154.50'], $h)->assertCreated();
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'EUR', 'rate_date' => '2026-11-10', 'rate' => '170.00'], $h)->assertCreated();
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'CBE USD account', 'kind' => 'bank', 'currency' => 'USD'], $h)->assertCreated()->json('id');
        $eur = $this->postJson('/api/v1/bank-accounts', ['name' => 'CBE EUR account', 'kind' => 'bank', 'currency' => 'EUR'], $h)->assertCreated()->json('id');
        // 1,000.00 dollars in first, from birr at the day's rate.
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $c['bank'], 'to_account_id' => $usd,
            'amount' => '154500', 'amount_received' => '1000'], $h)->assertCreated();

        $transfer = fn (array $over = []) => $this->postJson('/api/v1/banking/transfers', [...['date' => '2026-11-10', 'from_account_id' => $usd, 'to_account_id' => $eur,
            'amount' => '500', 'amount_received' => '450', 'fee' => '5'], ...$over], $h);
        $transfer(['amount_received' => null])->assertUnprocessable()->assertJsonValidationErrors('amount_received');

        // 500.00 dollars (77,250.00 birr) became 450.00 euros (76,500.00 birr): 750.00 lost on the exchange; a 5.00 dollar fee.
        $in = $transfer()->assertCreated()->json();
        $fx = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'fx_gain_loss'])->value('id');
        $transit = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'currency_transit'])->value('id');
        $this->assertNotSame('', $transit);
        $net = fn (string $account) => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account])
            ->selectRaw('coalesce(sum(debit) - sum(credit), 0)::numeric(20,2)::text AS n')->value('n');
        $this->assertSame('0.00', $net($transit), 'money between currencies is left at nothing');
        $this->assertSame('750.00', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $in['id'], 'account_id' => $fx])->value('debit'));
        $this->assertSame(['USD', 'EUR'], [
            $this->owner()->table('journal_entries')->where('id', $in['first_entry']['id'])->value('currency'),
            $this->owner()->table('journal_entries')->where('id', $in['id'])->value('currency'),
        ]);
        $this->getJson("/api/v1/bank-accounts/{$usd}", $h)->assertOk()->assertJsonPath('balance', '495.00');   // 1,000 - 500 - 5
        $this->getJson("/api/v1/bank-accounts/{$eur}", $h)->assertOk()->assertJsonPath('balance', '450.00')->assertJsonPath('balance_base', '76500.00');

        // Rates given on the transfer win: 100.00 dollars at 150.00 (15,000.00) became 90.00 euros at 168.00 (15,120.00), a 120.00 gain.
        $given = $transfer(['amount' => '100', 'amount_received' => '90', 'fee' => null, 'fx_rate' => '150', 'fx_rate_to' => '168'])->assertCreated()->json();
        $this->assertSame('120.00', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $given['id'], 'account_id' => $fx])->value('credit'));
        $this->assertSame('0.00', $net($transit));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'money_transferred'])
            ->where('after->rate_to', '168')->count());
    }
}
