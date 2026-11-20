<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1 (096): moving from QuickBooks, Sage or Tally. Their own exports come in as they are: QuickBooks' account list
 * (its types become a type and kind; a bank and the receivable are left out with where they go instead), Tally's
 * ledgers by group, QuickBooks' customer list with "Net 45" terms, Sage's supplier list and QuickBooks' products.
 */
final class GuidedImportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_exports_from_other_books_are_read_as_they_are(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $import = fn (string $kind, string $csv) => $this->postJson("/api/v1/imports/{$kind}", ['csv' => $csv], $h);
        $account = fn (string $code) => $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => $code])->first(['name', 'type', 'subtype']);

        // QuickBooks: Reports, Account List, exported with account numbers on.
        $qb = $import('accounts', "Account #,Account,Type,Detail Type,Description,Balance\n"
            . "Q7100,Fair stands,Expenses,Advertising/Promotional,Booths at trade fairs,0\n"
            . "Q1810,Coffee roaster,Fixed Assets,Machinery & Equipment,,0\n"
            . "Q2710,Development bank loan,Long Term Liabilities,Notes Payable,,0\n"
            . "Q1001,Dashen current,Bank,Checking,,0\n"
            . "Q1200,Accounts Receivable,Accounts receivable (A/R),Accounts Receivable (A/R),,0\n")
            ->assertCreated()->assertJsonPath('added', 3)->assertJsonCount(2, 'left_out')->json();
        $this->assertStringContainsString('Banking', $qb['left_out'][0]['reason']);
        $this->assertSame(['Dashen current', 'Accounts Receivable'], [$qb['left_out'][0]['name'], $qb['left_out'][1]['name']]);
        $this->assertContains('detail_type', $qb['ignored_columns']);
        $this->assertSame(['Fair stands', 'expense', null], [$account('Q7100')->name, $account('Q7100')->type, $account('Q7100')->subtype]);
        $this->assertSame(['asset', 'fixed_asset'], [$account('Q1810')->type, $account('Q1810')->subtype]);
        $this->assertSame(['liability', 'loan'], [$account('Q2710')->type, $account('Q2710')->subtype]);
        $this->assertNull($account('Q1001'));

        // Tally: ledgers with their group, a code column added.
        $import('accounts', "Code,Name,Under\nT1,Import duty payable,Duties & Taxes\nT2,Owner's capital,Capital Account\nT3,Debtors,Sundry Debtors\n")
            ->assertCreated()->assertJsonPath('added', 2)->assertJsonPath('left_out.0.name', 'Debtors');
        $this->assertSame(['liability', 'tax_payable'], [$account('T1')->type, $account('T1')->subtype]);
        $this->assertSame(['equity', 'capital'], [$account('T2')->type, $account('T2')->subtype]);

        // QuickBooks customers: its headings and terms.
        $import('customers', "Customer,Company,Email,Phone,Billing Street,Billing City,Billing State,Terms\n"
            . "Abebe Cafe,Abebe Cafe PLC,abebe@example.test,0911000000,Bole Road,Addis Ababa,Addis Ababa,Net 45\n"
            . "Kaldis Coffee,,,,,,,Due on receipt\n")->assertCreated()->assertJsonPath('added', 2);
        $customer = fn (string $name) => $this->owner()->table('contacts')->where(['company_id' => $c['company'], 'name' => $name])->first(['email', 'payment_terms_days', 'is_customer']);
        $this->assertSame(['abebe@example.test', 45], [$customer('Abebe Cafe')->email, (int) $customer('Abebe Cafe')->payment_terms_days]);
        $this->assertSame(0, (int) $customer('Kaldis Coffee')->payment_terms_days);

        // Sage suppliers.
        $import('suppliers', "A/C,Name,Telephone,VAT Reg No,Street1,Town,Post Code\nMOP01,Moplaco Trading,0115000000,0012345678,Piassa,Addis Ababa,1000\n")
            ->assertCreated()->assertJsonPath('added', 1);
        $this->assertTrue((bool) $this->owner()->table('contacts')->where(['company_id' => $c['company'], 'name' => 'Moplaco Trading'])->value('is_vendor'));

        // QuickBooks products and services: their types and the price with a thousands separator.
        $import('items', "Product/Service Name,SKU,Type,Sales Price / Rate,Purchase Cost\nYirgacheffe 1kg,YG-1,Non-inventory,\"1,200.00\",900\nRoasting,ROAST,Service,50,\n")
            ->assertCreated()->assertJsonPath('added', 2);
        $item = $this->owner()->table('items')->where(['company_id' => $c['company'], 'sku' => 'YG-1'])->first(['name', 'type', 'sale_price']);
        $this->assertSame(['Yirgacheffe 1kg', 'non_inventory', '1200.00'], [$item->name, $item->type, (string) $item->sale_price]);
    }
}
