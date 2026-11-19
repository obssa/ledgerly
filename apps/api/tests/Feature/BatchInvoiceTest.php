<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: batch invoices. One invoice's lines go to three customers at once: three drafts dated the same day, each
 * due by its customer's terms; posted when asked, by a role that may post. All or none: an archived customer, a
 * supplier or another company's customer stops the batch and nothing is made.
 */
final class BatchInvoiceTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_one_invoice_is_made_for_each_customer_or_none(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $contact = function (array $row) use ($c) {
            $id = (string) Str::uuid();
            $this->owner()->table('contacts')->insert(['id' => $id, 'company_id' => $c['company'], 'is_customer' => true, ...$row]);

            return $id;
        };
        $net30 = $contact(['name' => 'Harar Cafe', 'payment_terms_days' => 30]);
        $net7 = $contact(['name' => 'Bole Bakery', 'payment_terms_days' => 7]);
        $archived = $contact(['name' => 'Closed Shop', 'is_active' => false]);
        $supplier = $contact(['name' => 'Bean Supplier', 'is_customer' => false, 'is_vendor' => true]);
        $count = fn () => $this->owner()->table('documents')->where(['company_id' => $c['company'], 'type' => 'invoice'])->count();

        $source = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB', 'notes' => 'Monthly membership',
            'lines' => [['description' => 'Membership', 'quantity' => '1', 'unit_price' => '750', 'account_id' => $c['sales'], 'tax_rate_id' => $c['tax_rate']]]], $h)
            ->assertCreated()->json('id');
        $before = $count();

        // All or none.
        foreach ([$archived, $supplier, $b['contact']] as $bad) {
            $this->postJson("/api/v1/invoices/{$source}/batch", ['contact_ids' => [$net30, $bad]], $h)->assertUnprocessable()->assertJsonValidationErrors('contact_ids');
        }
        $this->postJson("/api/v1/invoices/{$source}/batch", ['contact_ids' => []], $h)->assertUnprocessable();
        $this->assertSame($before, $count());

        $made = $this->postJson("/api/v1/invoices/{$source}/batch", ['contact_ids' => [$net30, $net7, $c['contact']]], $h)->assertCreated()
            ->assertJsonCount(3, 'data')->json('data');
        $this->assertSame($before + 3, $count());
        foreach ($made as $m) {
            $this->assertSame(['draft', '787.50'], [$m['status'], $m['total']]);
        }
        $due = $this->owner()->table('documents')->whereIn('id', array_column($made, 'id'))->pluck('due_date', 'contact_id')->map(fn ($d) => substr((string) $d, 0, 10));
        $this->assertSame((new DateTimeImmutable($today))->modify("+30 days")->format('Y-m-d'), $due[$net30]);
        $this->assertSame((new DateTimeImmutable($today))->modify("+7 days")->format('Y-m-d'), $due[$net7]);
        $this->assertSame('Monthly membership', $this->owner()->table('documents')->where('id', $made[0]['id'])->value('notes'));

        // Posted as they are made.
        $posted = $this->postJson("/api/v1/invoices/{$source}/batch", ['contact_ids' => [$net7], 'post' => true], $h)->assertCreated()->json('data.0');
        $this->assertSame('posted', $posted['status']);
        $this->assertNotNull($posted['number']);
        $this->assertNotNull($this->owner()->table('documents')->where('id', $posted['id'])->value('journal_entry_id'));
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $source, 'action' => 'batch_invoiced'])->count());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/invoices/{$source}/batch", ['contact_ids' => [$b['contact']]], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
