<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1 (094): custom tags. "Trade fair" goes on the coffee invoice, a 500.00 rent bill and a 100.00 journal
 * entry; "Addis launch" on the bill too. Profit by tag counts each transaction under each of its tags, from the
 * ledger. A tag comes off; a tag turned off stays where it is but goes on nothing new; names are used once in any
 * case; every change is audited. Another company finds none of it.
 */
final class TagTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_transactions_are_tagged_and_profit_is_reported_by_tag(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $fair = $this->postJson('/api/v1/tags', ['name' => 'Trade fair'], $h)->assertCreated()->json('id');
        $launch = $this->postJson('/api/v1/tags', ['name' => '  Addis   launch '], $h)->assertCreated()->assertJsonPath('name', 'Addis launch')->json('id');
        $this->postJson('/api/v1/tags', ['name' => ' trade FAIR'], $h)->assertUnprocessable()->assertJsonValidationErrors('name');

        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-15', 'currency' => 'ETB', 'reference' => 'FAIR-STAND',
            'lines' => [['description' => 'Stand rent', 'quantity' => '1', 'unit_price' => '500', 'account_id' => $c['rent']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $entry = $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-16', 'memo' => 'Fair tickets', 'lines' => [
            ['account_id' => $c['rent'], 'debit' => '100'], ['account_id' => $c['bank'], 'credit' => '100']]], $h)->assertCreated()->json('id');

        $this->putJson("/api/v1/invoices/{$c['invoice']}/tags", ['tag_ids' => [$fair]], $h)->assertOk()->assertJsonPath('data.0.id', $fair)->assertJsonPath('can.tag', true);
        $this->putJson("/api/v1/bills/{$bill}/tags", ['tag_ids' => [$fair, $launch]], $h)->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/v1/journal-entries/{$entry}/tags", ['tag_ids' => [$fair]], $h)->assertOk()->assertJsonCount(3, 'tags');   // with the fixture's tag
        $this->getJson("/api/v1/bills/{$bill}/tags", $h)->assertOk()->assertJsonCount(2, 'data');

        $sales = (string) $this->owner()->table('journal_lines as l')->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->where(['l.company_id' => $c['company'], 'e.source_id' => $c['invoice'], 'a.type' => 'income'])
            ->selectRaw('coalesce(sum(l.credit - l.debit), 0)::numeric(20,2)::text AS s')->value('s');
        $report = fn () => collect($this->getJson('/api/v1/reports/by-tag?from=2026-11-01&to=2026-11-30', $h)->assertOk()->json('data'))->keyBy('id');
        $r = $report();
        $this->assertSame([$sales, '600.00', bcsub($sales, '600.00', 2), 3], [$r[$fair]['income'], $r[$fair]['expenses'], $r[$fair]['profit'], $r[$fair]['transactions']]);
        $this->assertSame(['0.00', '500.00'], [$r[$launch]['income'], $r[$launch]['expenses']]);

        // Off the bill: the fair's costs are the journal entry's alone.
        $this->putJson("/api/v1/bills/{$bill}/tags", ['tag_ids' => [$launch]], $h)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('100.00', $report()[$fair]['expenses']);

        // Turned off: it stays on the bill, goes on nothing new, and its name is still taken.
        $this->putJson("/api/v1/tags/{$launch}", ['is_active' => false], $h)->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('used', 1);
        $this->putJson("/api/v1/invoices/{$c['invoice']}/tags", ['tag_ids' => [$fair, $launch]], $h)->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
        $this->putJson("/api/v1/bills/{$bill}/tags", ['tag_ids' => [$launch]], $h)->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/tags/{$fair}", ['name' => 'ADDIS LAUNCH'], $h)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/tags/{$fair}", ['name' => 'Trade fair 2026'], $h)->assertOk()->assertJsonPath('name', 'Trade fair 2026');
        $this->getJson('/api/v1/tags', $h)->assertOk()->assertJsonFragment(['id' => $fair, 'name' => 'Trade fair 2026', 'used' => 2]);
        $this->assertSame(4, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'tags_set'])->count());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company']];
        $this->getJson("/api/v1/invoices/{$c['invoice']}/tags", $hb)->assertNotFound();
        $this->putJson("/api/v1/tags/{$fair}", ['name' => 'Ours now'], $hb)->assertNotFound();
        $this->putJson("/api/v1/bills/{$b['bill']}/tags", ['tag_ids' => [$fair]], $hb)->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
        $this->assertArrayNotHasKey($fair, collect($this->getJson('/api/v1/reports/by-tag?from=2026-11-01&to=2026-11-30', $hb)->assertOk()->json('data'))->keyBy('id')->all());
    }
}
