<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use DateTimeImmutable;

/**
 * Turns an approved invoice, credit note or bill into a balanced journal draft:
 *
 *   Invoice:      Dr Accounts receivable (total)   Cr Income per account   Cr Tax payable per tax account
 *   Credit note:  the same lines with sides swapped
 *   Bill:         Cr Accounts payable (total)      Dr Expense or asset per account   Dr VAT receivable
 *   Expense:      Cr the bank, cash or wallet it was paid from, with the same debits as a bill
 *   Supplier credit: a bill's lines with sides swapped (Dr Accounts payable, Cr cost and VAT receivable)
 *
 * Foreign-currency documents: each credit group is converted to the base currency first and the receivable
 * is the sum of the converted credits, so rounding can never unbalance the entry.
 */
final class InvoicePostingBuilder
{
    /**
     * @param list<array{income_account_id: string, subtotal: Money, tax: Money, tax_account_id: ?string, location_id?: ?string, project_id?: ?string}> $lines
     */
    public function build(
        string $companyId,
        string $documentId,
        string $documentNumber,
        DateTimeImmutable $issueDate,
        string $baseCurrency,
        string $currency,
        string $fxRate,
        string $customerId,
        string $receivableAccountId,
        array $lines,
        bool $isCreditNote = false,
        ?string $sourceType = null,
    ): JournalDraft {
        $sourceType ??= $isCreditNote ? 'credit_note' : 'invoice';
        // The control account (receivable or payable) is debited for an invoice, credited for a credit note or a bill.
        $controlOnCredit = in_array($sourceType, ['credit_note', 'bill', 'expense'], true);
        $draft = new JournalDraft(
            companyId: $companyId,
            date: $issueDate,
            baseCurrency: $baseCurrency,
            currency: $currency,
            fxRate: $fxRate,
            memo: ['invoice' => 'Invoice ', 'credit_note' => 'Credit note ', 'bill' => 'Bill ', 'expense' => 'Expense ', 'vendor_credit' => 'Supplier credit ',
                'sales_receipt' => 'Cash sale '][$sourceType] . $documentNumber,
            sourceType: $sourceType,
            sourceId: $documentId,
        );

        // Group by account so the ledger stays compact: one line per income or cost account and project (job costing
        // reads the project from the ledger line), one per tax account (tax is never a project's).
        $groups = [];
        foreach ($lines as $line) {
            $this->addToGroup($groups, $line['income_account_id'] . '|' . ($line['project_id'] ?? ''), $line['subtotal']);
            if (! $line['tax']->isZero()) {
                $this->addToGroup($groups, ($line['tax_account_id'] ?? throw new \InvalidArgumentException('Taxed line without a tax account')) . '|', $line['tax']);
            }
        }

        $receivableBase = Money::zero($baseCurrency);
        $receivableTxn = Money::zero($currency);
        $creditLines = [];

        foreach ($groups as $key => $txnAmount) {
            if ($txnAmount->isZero()) {
                continue;
            }
            [$accountId, $projectId] = explode('|', $key, 2);
            $baseAmount = $currency === $baseCurrency
                ? $txnAmount
                : $txnAmount->convertTo($baseCurrency, $fxRate)->roundToCurrency();

            $receivableBase = $receivableBase->plus($baseAmount);
            $receivableTxn = $receivableTxn->plus($txnAmount);
            $creditLines[] = [$accountId, $baseAmount, $txnAmount, $projectId === '' ? [] : ['project_id' => $projectId]];
        }

        $draft->add($controlOnCredit
            ? JournalLineDraft::credit($receivableAccountId, $receivableBase, $receivableTxn, $documentNumber, $customerId)
            : JournalLineDraft::debit($receivableAccountId, $receivableBase, $receivableTxn, $documentNumber, $customerId));

        foreach ($creditLines as [$accountId, $base, $txn, $dimensions]) {
            $draft->add($controlOnCredit
                ? JournalLineDraft::debit($accountId, $base, $txn, $documentNumber, $customerId, $dimensions)
                : JournalLineDraft::credit($accountId, $base, $txn, $documentNumber, $customerId, $dimensions));
        }

        $draft->assertBalanced();

        return $draft;
    }

    /** @param array<string, Money> $groups */
    private function addToGroup(array &$groups, string $accountId, Money $amount): void
    {
        $groups[$accountId] = isset($groups[$accountId]) ? $groups[$accountId]->plus($amount) : $amount;
    }
}
