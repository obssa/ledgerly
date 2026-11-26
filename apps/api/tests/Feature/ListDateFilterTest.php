<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Every list filters by its own date, as QuickBooks' lists do: from and to, either left open; nothing else of the
 * request reaches the SQL. The fixture invoice is dated 2 November 2026, its payment 3 November, its credit note 4.
 */
final class ListDateFilterTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** Every list that takes a period. */
    private const LISTS = ['invoices', 'credit-notes', 'quotes', 'sales-orders', 'delivery-notes', 'sales-receipts', 'payments-received', 'refunds',
        'bills', 'supplier-credits', 'expenses', 'payments-made', 'purchase-orders', 'goods-receipts', 'expense-claims', 'purchase-requisitions'];

    public function test_each_list_keeps_only_its_period_and_refuses_what_is_not_a_date(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        foreach (self::LISTS as $list) {
            $this->getJson("/api/v1/{$list}?from=2099-01-01", $h)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("/api/v1/{$list}?to=1900-01-01", $h)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("/api/v1/{$list}?from=2026-11-30&to=2026-11-01", $h)->assertUnprocessable()->assertJsonValidationErrors('to');
            $this->getJson("/api/v1/{$list}?from=yesterday", $h)->assertUnprocessable()->assertJsonValidationErrors('from');
            $this->getJson("/api/v1/{$list}?to=2026-11-02%27%20OR%201%3D1", $h)->assertUnprocessable()->assertJsonValidationErrors('to');
        }

        // The invoice on its day, not the day after; the payment on its own date; open ends.
        $ids = fn (string $url) => collect($this->getJson($url, $h)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($c['invoice'], $ids('/api/v1/invoices?from=2026-11-02&to=2026-11-02'));
        $this->assertNotContains($c['invoice'], $ids('/api/v1/invoices?from=2026-11-03'));
        $this->assertContains($c['invoice'], $ids('/api/v1/invoices?to=2026-11-02'));
        $this->assertContains($c['payment'], $ids('/api/v1/payments-received?from=2026-11-03&to=2026-11-03'));
        $this->assertNotContains($c['payment'], $ids('/api/v1/payments-received?to=2026-11-02'));
        $this->assertContains($c['credit_note'], $ids('/api/v1/credit-notes?from=2026-11-04'));
        $this->assertContains($c['quote'], $ids('/api/v1/quotes?from=2026-11-01&to=2026-11-30'));
        // No dates: every one, as before.
        $this->assertContains($c['invoice'], $ids('/api/v1/invoices'));
    }

    public function test_a_period_never_shows_another_companys_records(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $a = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $userA = $this->lastUser;
        $b = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($userA, 'sanctum');
        $h = ['X-Company-Id' => $a['company']];
        foreach (['invoices' => 'invoice', 'payments-received' => 'payment', 'credit-notes' => 'credit_note', 'quotes' => 'quote'] as $list => $key) {
            $rows = collect($this->getJson("/api/v1/{$list}?from=2026-11-01&to=2026-11-30", $h)->assertOk()->json('data'))->pluck('id')->all();
            $this->assertContains($a[$key], $rows, "A's own {$list}");
            $this->assertNotContains($b[$key], $rows, "none of B's {$list}");
        }
    }
}
