<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Ledger\Money;

/**
 * Assets = Liabilities + Equity, where equity includes profit to date that has not yet been closed to
 * retained earnings. With a balanced ledger the difference is always zero; anything else means data was
 * written around the posting service and is reported, never hidden.
 */
final readonly class AccountingEquation
{
    public Money $equityIncludingProfit;

    public Money $difference;

    public function __construct(
        public Money $assets,
        public Money $liabilities,
        public Money $equity,
        public Money $profitToDate,
    ) {
        $this->equityIncludingProfit = $equity->plus($profitToDate);
        $this->difference = $assets->minus($liabilities->plus($this->equityIncludingProfit));
    }

    public function holds(): bool
    {
        return $this->difference->isZero();
    }

    public function toArray(): array
    {
        return [
            'assets' => $this->assets->toDecimalString(),
            'liabilities' => $this->liabilities->toDecimalString(),
            'equity' => $this->equity->toDecimalString(),
            'profit_to_date' => $this->profitToDate->toDecimalString(),
            'liabilities_and_equity' => $this->liabilities->plus($this->equityIncludingProfit)->toDecimalString(),
            'difference' => $this->difference->toDecimalString(),
            'balanced' => $this->holds(),
        ];
    }
}
