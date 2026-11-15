<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\Money;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * The credit-limit check. A warning, never a block (product rule: controls stay light): the invoice saves and posts,
 * and whoever saves or approves it sees that the customer goes over their limit.
 *
 * What the customer owes = posted invoices still open, in the base currency at each invoice's own rate, plus this
 * invoice when it is not posted yet.
 */
final class CreditCheck
{
    /** @return list<array{type: string, message: string, credit_limit: string, balance_after: string}> */
    public function warnings(Company $company, Document $invoice): array
    {
        $customer = Contact::query()->find($invoice->contact_id);
        if ($customer === null || $customer->credit_limit === null) {
            return [];
        }

        $base = $company->base_currency;
        $owed = (string) (DB::table('documents')
            ->where(['contact_id' => $customer->getKey(), 'type' => 'invoice'])
            ->whereIn('status', ['posted', 'part_paid'])
            ->where('id', '<>', $invoice->getKey())
            ->selectRaw('coalesce(sum(round(amount_due * fx_rate, 2)), 0)::numeric(20,2)::text as owed')
            ->value('owed') ?? '0');
        $this_ = Money::of($invoice->amount_due, $invoice->currency)->convertTo($base, (string) $invoice->fx_rate);
        $after = Money::of($owed, $base)->plus($this_);
        $limit = Money::of((string) $customer->credit_limit, $base);

        if (! $limit->minus($after)->isNegative()) {
            return [];
        }

        return [[
            'type' => 'CreditLimit',
            'message' => "{$customer->name} would owe {$after->toDecimalString()} {$base}, over their credit limit of {$limit->toDecimalString()} {$base}.",
            'credit_limit' => $limit->toDecimalString(),
            'balance_after' => $after->toDecimalString(),
        ]];
    }
}
