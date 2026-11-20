<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 1: customers, suppliers and items from a CSV file, checked first, then all added or none. */
final class ImportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_checked_file_is_added_whole_through_the_same_rules_as_by_hand(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $customers = fn () => $this->owner()->table('contacts')->where(['company_id' => $c['company'], 'is_customer' => true])->count();
        $before = $customers();

        $bad = "\u{FEFF}Name,TIN,Email,Terms,Credit limit,City,Favourite colour\nKaldi Cafe,,kaldi@example.test,30,\"5,000.00\",Addis Ababa,green\nTomoca,,not-an-email,15,,Addis Ababa,\nGarden Hotel,,hotel@example.test,,,Adama,\n";
        $checked = $this->postJson('/api/v1/imports/customers', ['csv' => $bad, 'check' => true], $h)->assertOk()
            ->assertJsonPath('rows', 3)->assertJsonPath('added', 0)->assertJsonPath('errors.0.row', 3)->assertJsonPath('errors.0.name', 'Tomoca')
            ->assertJsonPath('ignored_columns', ['favourite_colour']);
        $this->assertStringContainsString('email', strtolower(implode(' ', $checked->json('errors.0.messages'))));
        $this->postJson('/api/v1/imports/customers', ['csv' => $bad], $h)->assertOk()->assertJsonPath('added', 0);   // one bad row: none added
        $this->assertSame($before, $customers());

        $good = str_replace('not-an-email', 'tomoca@example.test', $bad);
        $this->postJson('/api/v1/imports/customers', ['csv' => $good], $h)->assertCreated()->assertJsonPath('added', 3)->assertJsonPath('errors', []);
        $this->assertSame($before + 3, $customers());
        $kaldi = $this->owner()->table('contacts')->where(['company_id' => $c['company'], 'name' => 'Kaldi Cafe'])->first();
        $this->assertSame(['5000.00', 30], [(string) $kaldi->credit_limit, (int) $kaldi->payment_terms_days]);
        $this->assertSame('Addis Ababa', json_decode((string) $kaldi->address, true)['city']);
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'created', 'subject_type' => 'customer'])
            ->whereIn('subject_id', $this->owner()->table('contacts')->where('company_id', $c['company'])->whereIn('name', ['Kaldi Cafe', 'Tomoca', 'Garden Hotel'])->pluck('id'))->count());

        // Items, semicolon separated (as some spreadsheets save): a code twice in the file and nothing is added.
        $items = "Name;Code;Type;Unit;Price\nGreen beans 60 kg;GB-60;Inventory;bag;12 500\nRoasting service;RS-1;service;hour;800\nGreen beans again;GB-60;inventory;bag;1\n";
        $this->postJson('/api/v1/imports/items', ['csv' => $items], $h)->assertOk()->assertJsonPath('added', 0)->assertJsonPath('errors.0.row', 4);
        $this->postJson('/api/v1/imports/items', ['csv' => preg_replace('/\nGreen beans again[^\n]*\n$/', "\n", $items)], $h)->assertCreated()->assertJsonPath('added', 2);
        $this->assertSame('12500.00', (string) $this->owner()->table('items')->where(['company_id' => $c['company'], 'sku' => 'GB-60'])->value('sale_price'));

        $this->postJson('/api/v1/imports/suppliers', ['csv' => "Name,Phone\nAddis Packaging,+251 911 000 000\n"], $h)->assertCreated()->assertJsonPath('added', 1);
        $this->postJson('/api/v1/imports/customers', ['csv' => "Client,Email\nSomeone,x@example.test\n"], $h)->assertUnprocessable()->assertJsonValidationErrors('csv');

        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $this->postJson('/api/v1/imports/customers', ['csv' => $good], $h)->assertForbidden();
    }
}
