<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: bank rules (076). A rule for the bank's SMS alert charge (out, up to 10.00) is suggested on the line it fits
 * and categorises it, with others like it, when the rules are applied: each through the same entry as categorising by
 * hand (Dr Bank charges, Cr Bank). A rule never goes to an account kept by its own documents, its range is the right way
 * round, and an archived or deleted rule fits nothing. Every change is audited; another company never sees a rule.
 */
final class BankRuleTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_rules_suggest_and_categorise_the_lines_they_fit(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // statement lines: fuel 115.00, SMS alert charge 2.50
        $h = ['X-Company-Id' => $c['company']];
        $system = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $charges = $system('bank_charges');
        $lines = fn () => collect($this->getJson("/api/v1/bank-accounts/{$c['bank']}/statement-lines", $h)->assertOk()->json('data'))->keyBy('description');
        $rule = ['name' => 'SMS charges', 'contains' => 'sms alert', 'direction' => 'out', 'amount_max' => '10', 'target_account_id' => $charges, 'bank_account_id' => $c['bank']];

        // Never to an account kept by its own documents, nor to the account it is for; a sound range; words to look for.
        $this->postJson('/api/v1/bank-rules', [...$rule, 'target_account_id' => $system('accounts_receivable')], $h)->assertUnprocessable()->assertJsonValidationErrors('target_account_id');
        $this->postJson('/api/v1/bank-rules', [...$rule, 'target_account_id' => $c['bank']], $h)->assertUnprocessable()->assertJsonValidationErrors('target_account_id');
        $this->postJson('/api/v1/bank-rules', [...$rule, 'amount_min' => '20'], $h)->assertUnprocessable()->assertJsonValidationErrors('amount_max');
        $this->postJson('/api/v1/bank-rules', [...$rule, 'contains' => '   '], $h)->assertUnprocessable();
        $id = $this->postJson('/api/v1/bank-rules', $rule, $h)->assertCreated()->assertJsonPath('direction', 'out')->json('id');

        // Suggested on the line it fits, not on the fuel.
        $this->assertSame($id, $lines()['SMS alert charge']['rule']['rule_id']);
        $this->assertNull($lines()['Fuel station R-7781']['rule']);

        // Applied: the SMS charge is categorised to bank charges; the fuel stays to review.
        $this->postJson("/api/v1/bank-accounts/{$c['bank']}/apply-rules", [], $h)->assertOk()->assertJsonPath('categorised', 1)->assertJsonPath('skipped', []);
        $sms = $this->owner()->table('bank_transactions')->where(['company_id' => $c['company'], 'description' => 'SMS alert charge'])->first(['status', 'journal_line_id']);
        $this->assertSame('categorised', $sms->status);
        $entry = $this->owner()->table('journal_lines')->where('id', $sms->journal_line_id)->value('journal_entry_id');
        $this->assertSame('2.50', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $charges])->value('debit'));
        $audit = json_decode((string) $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'statement_line_categorised'])->latest('id')->value('after'), true);
        $this->assertSame('SMS charges', $audit['rule']);

        // A new statement: another SMS charge fits; one above the range does not.
        $this->postJson("/api/v1/bank-accounts/{$c['bank']}/statements", ['csv' => "Date,Description,Amount\n12/11/2026,SMS ALERT CHARGE NOV,-3.00\n12/11/2026,SMS alert bundle,-25.00\n"], $h)->assertCreated();
        $this->postJson("/api/v1/bank-accounts/{$c['bank']}/apply-rules", [], $h)->assertOk()->assertJsonPath('categorised', 1);
        $this->assertSame('imported', $lines()['SMS alert bundle']['status']);

        // Archived, it fits nothing; deleted, it is gone, and both are audited.
        $this->putJson("/api/v1/bank-rules/{$id}", ['is_active' => false], $h)->assertOk()->assertJsonPath('is_active', false);
        $this->assertNull($lines()['SMS alert bundle']['rule']);
        $this->putJson("/api/v1/bank-rules/{$id}", ['amount_min' => '50'], $h)->assertUnprocessable()->assertJsonValidationErrors('amount_max');

        // Another company sees none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/bank-rules', $hb)->assertOk()->json('data'), 'id'));
        $this->putJson("/api/v1/bank-rules/{$id}", ['is_active' => true], $hb)->assertNotFound();
        $this->deleteJson("/api/v1/bank-rules/{$id}", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/bank-accounts/{$c['bank']}/apply-rules", [], $hb)->assertNotFound();
        $this->postJson('/api/v1/bank-rules', [...$rule, 'bank_account_id' => null], $hb)->assertUnprocessable()->assertJsonValidationErrors('target_account_id');
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $this->deleteJson("/api/v1/bank-rules/{$id}", [], $h)->assertOk();
        $actions = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $id])->pluck('action')->all();
        foreach (['bank_rule_created', 'bank_rule_updated', 'bank_rule_deleted'] as $a) {
            $this->assertContains($a, $actions);
        }
    }
}
