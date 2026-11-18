<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1: the chart of accounts and the opening trial balance from CSV. Accounts go through POST accounts row by row
 * (a parent named by its code; codes already in the chart are skipped); opening balances go through PUT
 * opening-balances as one set. A check keeps nothing; a file with one bad row adds nothing. Another company's
 * accounts are never found by code.
 */
final class ChartImportTest extends TestCase
{
    use RefreshLedgerDatabase;

    private string $company;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        // Company B first, with an account code A's file will name: it must not be found from company A.
        $this->actingAs($this->createUser('Selam', 'selam@example.test'), 'sanctum');
        $b = $this->postJson('/api/v1/companies', ['legal_name' => 'Blue Nile Traders', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->assertCreated()->json('id');
        $this->postJson('/api/v1/accounts', ['code' => '7777', 'name' => 'B only', 'type' => 'expense', 'kind' => 'operating'], ['X-Company-Id' => $b])->assertCreated();

        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])
            ->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
    }

    private function code(string $key): string
    {
        return (string) $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => $key])->value('code');
    }

    private function accounts(): int
    {
        return $this->owner()->table('accounts')->where('company_id', $this->company)->count();
    }

    public function test_a_chart_is_added_whole_with_parents_by_code_and_existing_codes_skipped(): void
    {
        $before = $this->accounts();
        $bank = $this->code('bank');
        $csv = "Code,Name,Type,Kind,Parent\nMK100,Marketing,Expenses,operating,\nMK110,Social media ads,expense,operating,MK100\nMK120,Printed flyers,expense,operating,7777\n{$bank},Our bank,asset,,\n";

        $this->postJson('/api/v1/imports/accounts', ['csv' => $csv, 'check' => true], $this->h)->assertOk()
            ->assertJsonPath('rows', 4)->assertJsonPath('added', 0)->assertJsonPath('errors.0.row', 4)->assertJsonPath('skipped', [$bank]);
        $this->postJson('/api/v1/imports/accounts', ['csv' => $csv], $this->h)->assertOk()->assertJsonPath('added', 0);   // company B's 7777 is not found
        $this->assertSame($before, $this->accounts());

        $this->postJson('/api/v1/imports/accounts', ['csv' => str_replace(',7777', ',MK100', $csv)], $this->h)->assertCreated()
            ->assertJsonPath('added', 3)->assertJsonPath('errors', [])->assertJsonPath('skipped', [$bank]);
        $this->assertSame($before + 3, $this->accounts());
        $parent = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'code' => 'MK100'])->value('id');
        $this->assertSame(2, $this->owner()->table('accounts')->where(['company_id' => $this->company, 'parent_id' => $parent])->count());
        $this->assertSame('expense', $this->owner()->table('accounts')->where(['company_id' => $this->company, 'code' => 'MK100'])->value('type'));
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'action' => 'created', 'subject_type' => 'account'])->count());

        $this->postJson('/api/v1/imports/accounts', ['csv' => "Name\nNo code"], $this->h)->assertUnprocessable()->assertJsonValidationErrors('csv');
    }

    public function test_an_opening_trial_balance_replaces_the_opening_balances_as_one_set(): void
    {
        [$bank, $vat, $ar] = [$this->code('bank'), $this->code('vat_payable'), $this->code('accounts_receivable')];
        $csv = "Code,Name,Debit,Credit\n{$bank},Bank,\"50,000.00\",\n{$vat},VAT payable,,5000\n";

        $this->postJson('/api/v1/imports/opening-balances', ['csv' => $csv], $this->h)->assertOk()->assertJsonPath('added', 0)
            ->assertJsonPath('errors.0.messages.0', 'Set the conversion date under Company settings first: opening balances are as at that date.');
        $version = $this->getJson('/api/v1/company', $this->h)->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'conversion_date' => '2026-07-07'], $this->h)->assertOk();

        // Unknown codes and control accounts (their balance comes from open invoices) are named by row; a check keeps nothing.
        $bad = $csv . "7777,B only,100,\n{$ar},Receivables,900,\n";
        $checked = $this->postJson('/api/v1/imports/opening-balances', ['csv' => $bad, 'check' => true], $this->h)->assertOk()->assertJsonPath('added', 0);
        $this->assertSame([4], array_column($checked->json('errors'), 'row'));
        $checked = $this->postJson('/api/v1/imports/opening-balances', ['csv' => $csv . "{$ar},Receivables,900,\n", 'check' => true], $this->h)->assertOk();
        $this->assertSame([4], array_column($checked->json('errors'), 'row'));
        $this->postJson('/api/v1/imports/opening-balances', ['csv' => $csv, 'check' => true], $this->h)->assertOk()->assertJsonPath('errors', [])->assertJsonPath('added', 0);
        $opening = fn () => $this->owner()->table('journal_entries')->where(['company_id' => $this->company, 'source_type' => 'opening'])->count();
        $this->assertSame(0, $opening());

        $this->postJson('/api/v1/imports/opening-balances', ['csv' => $csv], $this->h)->assertCreated()->assertJsonPath('added', 2);
        $this->getJson('/api/v1/opening-balances', $this->h)->assertOk()->assertJsonPath('difference', '45000.00');

        // Again, as one signed balance per account: the earlier entry is reversed and replaced.
        $this->postJson('/api/v1/imports/opening-balances', ['csv' => "Code,Balance\n{$bank},60000\n{$vat},-5000\n"], $this->h)->assertCreated()->assertJsonPath('added', 2);
        $this->getJson('/api/v1/opening-balances', $this->h)->assertOk()->assertJsonPath('difference', '55000.00');
        $this->assertSame(2, $opening());   // the first (reversed) and the second
        $this->assertSame(2, $this->owner()->table('audit_log')->where('company_id', $this->company)->whereIn('action', ['opening_balances_entered', 'opening_balances_replaced'])->count());
    }
}
