<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Exceptions\UnbalancedEntry;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JournalDraftTest extends TestCase
{
    private function draft(): JournalDraft
    {
        return new JournalDraft('company-a', new DateTimeImmutable('2026-11-02'), 'ETB', 'ETB');
    }

    public function test_balanced_entry_passes(): void
    {
        $draft = $this->draft()
            ->add(JournalLineDraft::debit('cash', Money::of('500', 'ETB')))
            ->add(JournalLineDraft::credit('sales', Money::of('500', 'ETB')));

        $draft->assertBalanced();

        $this->assertTrue($draft->totalDebit()->equals($draft->totalCredit()));
    }

    public function test_unbalanced_entry_is_refused(): void
    {
        $this->expectException(UnbalancedEntry::class);

        $this->draft()
            ->add(JournalLineDraft::debit('cash', Money::of('500', 'ETB')))
            ->add(JournalLineDraft::credit('sales', Money::of('499.99', 'ETB')))
            ->assertBalanced();
    }

    public function test_single_line_entry_is_refused(): void
    {
        $this->expectException(UnbalancedEntry::class);

        $this->draft()->add(JournalLineDraft::debit('cash', Money::of('1', 'ETB')))->assertBalanced();
    }

    public function test_zero_and_negative_lines_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JournalLineDraft::debit('cash', Money::of('-5', 'ETB'));
    }

    public function test_lines_must_be_in_base_currency(): void
    {
        $this->expectException(UnbalancedEntry::class);

        $this->draft()->add(JournalLineDraft::debit('cash', Money::of('5', 'USD')));
    }

    public function test_reversal_swaps_every_side(): void
    {
        $original = $this->draft()
            ->add(JournalLineDraft::debit('ar', Money::of('115', 'ETB')))
            ->add(JournalLineDraft::credit('sales', Money::of('100', 'ETB')))
            ->add(JournalLineDraft::credit('vat', Money::of('15', 'ETB')));

        $reversal = JournalDraft::reversalOf($original, new DateTimeImmutable('2026-11-03'), 'entry-1');
        $reversal->assertBalanced();

        [$ar, $sales, $vat] = $reversal->lines();
        $this->assertSame('115.0000', $ar->credit->toDecimalString());
        $this->assertSame('100.0000', $sales->debit->toDecimalString());
        $this->assertSame('15.0000', $vat->debit->toDecimalString());
        $this->assertSame('reversal', $reversal->sourceType);
    }
}
