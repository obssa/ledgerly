<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\BankingPostingBuilder;
use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bank deposits and transfers between the company's own bank, wallet and cash accounts. */
final class BankingController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly BankingPostingBuilder $builder,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly ExchangeRates $rates,
    ) {
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.transfer');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'from_account_id' => ['required', 'uuid', 'different:to_account_id'],
            'to_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],                  // sent, in the source account's currency
            'amount_received' => ['nullable', 'decimal:0,2', 'gt:0'],         // in the destination's currency, when it differs
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],                 // base per unit of the foreign currency; else the day's rate
            'fx_rate_to' => ['nullable', 'decimal:0,6', 'gt:0'],              // between two foreign currencies: the destination's rate
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],                    // in the source account's currency
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
        $company = $this->current->get();
        $base = $company->base_currency;
        $from = $this->moneyAccount($data['from_account_id'], 'from_account_id', baseOnly: false);
        $to = $this->moneyAccount($data['to_account_id'], 'to_account_id', baseOnly: false);
        $fromCurrency = $from->currency ?? $base;
        $toCurrency = $to->currency ?? $base;
        $memo = $data['memo'] ?? "Transfer from {$from->name} to {$to->name}";
        $date = new DateTimeImmutable($data['date']);

        if ($fromCurrency === $base && $toCurrency === $base) {
            $draft = $this->builder->transfer(
                $this->current->id(), $date, $from->getKey(), $to->getKey(), Money::of($data['amount'], $base),
                isset($data['fee']) ? Money::of($data['fee'], $base) : null, Account::system('bank_charges')->getKey(), $memo,
            );
            $rate = null;
        } elseif ($fromCurrency !== $base && $toCurrency !== $base && $fromCurrency !== $toCurrency) {
            return $this->betweenForeignCurrencies($request, $data, $from, $to, $fromCurrency, $toCurrency, $memo, $date);
        } else {
            $foreign = $fromCurrency !== $base ? $fromCurrency : $toCurrency;
            if ($fromCurrency !== $toCurrency && ! isset($data['amount_received'])) {
                throw ValidationException::withMessages(['amount_received' => ["Enter how much {$toCurrency} arrived in {$to->name}."]]);
            }
            $rate = $this->rates->forDocument($company, $foreign, $data['date'], $data['fx_rate'] ?? null);
            $draft = $this->builder->currencyTransfer(
                $this->current->id(), $date, $base, $from->getKey(), $to->getKey(),
                Money::of($data['amount'], $fromCurrency),
                Money::of($fromCurrency === $toCurrency ? $data['amount'] : $data['amount_received'], $toCurrency),
                $rate, Account::system('fx_gain_loss')->getKey(),
                isset($data['fee']) ? Money::of($data['fee'], $fromCurrency) : null, Account::system('bank_charges')->getKey(), $memo,
            );
        }
        $entry = $this->posting->post($draft, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'money_transferred', 'bank_account', $from->getKey(), null,
            ['to' => $to->name, 'amount' => $data['amount'], 'currency' => $fromCurrency,
                'received' => $fromCurrency === $toCurrency ? $data['amount'] : ($data['amount_received'] ?? $data['amount']), 'received_currency' => $toCurrency,
                'rate' => $rate, 'fee' => $data['fee'] ?? '0', 'journal_entry' => $entry->number], userId: $request->user()->getKey());

        return response()->json($entry, 201);
    }

    /**
     * Between two foreign currencies (dollars to euros): one entry in each currency, each at its own rate (given, or the
     * day's), joined through "Money between currencies", which they leave at nothing. The first takes the amount sent
     * (and any fee) out of the source account; the second puts what arrived into the destination, and the difference
     * between the two values in the base currency is the exchange gain or loss.
     *
     * @param array<string, mixed> $data
     */
    private function betweenForeignCurrencies(Request $request, array $data, Account $from, Account $to, string $fromCurrency, string $toCurrency, string $memo, DateTimeImmutable $date): JsonResponse
    {
        if (! isset($data['amount_received'])) {
            throw ValidationException::withMessages(['amount_received' => ["Enter how much {$toCurrency} arrived in {$to->name}."]]);
        }
        $company = $this->current->get();
        $base = $company->base_currency;
        $fromRate = $this->rates->forDocument($company, $fromCurrency, $data['date'], $data['fx_rate'] ?? null);
        $toRate = $this->rates->forDocument($company, $toCurrency, $data['date'], $data['fx_rate_to'] ?? null);
        $transit = $this->transitAccount($company);
        $sent = Money::of($data['amount'], $fromCurrency);
        $received = Money::of($data['amount_received'], $toCurrency);
        $sentBase = $sent->convertTo($base, $fromRate)->roundToCurrency();
        $receivedBase = $received->convertTo($base, $toRate)->roundToCurrency();
        $userId = $request->user()->getKey();

        [$out, $in] = DB::transaction(function () use ($company, $base, $date, $from, $to, $fromCurrency, $toCurrency, $fromRate, $toRate, $transit, $sent, $received, $sentBase, $receivedBase, $data, $memo, $userId) {
            $first = new JournalDraft($company->getKey(), $date, $base, $fromCurrency, $fromRate, "{$memo} ({$fromCurrency} out)", 'transfer');
            $first->add(JournalLineDraft::credit($from->getKey(), $sentBase, $sent, 'Transfer out'));
            $first->add(JournalLineDraft::debit($transit->getKey(), $sentBase, Money::zero($fromCurrency), "To {$to->name} in {$toCurrency}"));
            if (isset($data['fee']) && ! Money::of($data['fee'], $fromCurrency)->isZero()) {
                $fee = Money::of($data['fee'], $fromCurrency);
                $feeBase = $fee->convertTo($base, $fromRate)->roundToCurrency();
                $first->add(JournalLineDraft::debit(Account::system('bank_charges')->getKey(), $feeBase, $fee, 'Transfer fee'));
                $first->add(JournalLineDraft::credit($from->getKey(), $feeBase, $fee, 'Transfer fee'));
            }
            $out = $this->posting->post($first, $userId);

            $second = new JournalDraft($company->getKey(), $date, $base, $toCurrency, $toRate, "{$memo} ({$toCurrency} in)", 'transfer');
            $second->add(JournalLineDraft::debit($to->getKey(), $receivedBase, $received, 'Transfer in'));
            $second->add(JournalLineDraft::credit($transit->getKey(), $sentBase, Money::zero($toCurrency), "From {$from->name} in {$fromCurrency}"));
            $difference = $sentBase->minus($receivedBase);
            if (! $difference->isZero()) {
                $fx = Account::system('fx_gain_loss')->getKey();
                $second->add($difference->isNegative()
                    ? JournalLineDraft::credit($fx, $difference->negated(), Money::zero($toCurrency), 'Exchange gain on transfer')
                    : JournalLineDraft::debit($fx, $difference, Money::zero($toCurrency), 'Exchange loss on transfer'));
            }

            return [$out, $this->posting->post($second, $userId)];
        });
        $this->audit->record($company->getKey(), 'money_transferred', 'bank_account', $from->getKey(), null,
            ['to' => $to->name, 'amount' => $data['amount'], 'currency' => $fromCurrency, 'received' => $data['amount_received'], 'received_currency' => $toCurrency,
                'rate' => $fromRate, 'rate_to' => $toRate, 'fee' => $data['fee'] ?? '0', 'journal_entry' => $out->number, 'journal_entry_in' => $in->number], userId: $userId);

        return response()->json([...$in->toArray(), 'first_entry' => ['id' => $out->getKey(), 'number' => $out->number]], 201);
    }

    /**
     * "Money between currencies", made the first time two foreign currencies are exchanged: an asset each such transfer
     * passes through and leaves at nothing.
     */
    private function transitAccount(\App\Domain\Tenancy\Models\Company $company): Account
    {
        $existing = Account::query()->where('system_key', 'currency_transit')->first();
        if ($existing !== null) {
            return $existing;
        }
        $taken = DB::table('accounts')->where('company_id', $company->getKey())->pluck('code')->all();
        $code = collect(['1190', ...array_map(fn ($n) => (string) $n, range(1191, 1199))])->first(fn ($c) => ! in_array($c, $taken, true)) ?? 'FXT-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4));
        $account = new Account();
        $account->forceFill(['company_id' => $company->getKey(), 'code' => $code, 'name' => 'Money between currencies', 'type' => 'asset',
            'system_key' => 'currency_transit', 'is_active' => true])->save();

        return $account;
    }

    public function deposit(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.deposit');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'bank_account_id' => ['required', 'uuid'],
            'slip_number' => ['nullable', 'string', 'max:40'],
            'receipts' => ['required', 'array', 'min:1', 'max:500'],
            'receipts.*' => ['required', 'decimal:0,2', 'gt:0'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);
        $currency = $this->current->get()->base_currency;
        $bank = $this->moneyAccount($data['bank_account_id'], 'bank_account_id', ['bank', 'mobile_money']);

        $draft = $this->builder->deposit(
            $this->current->id(), new DateTimeImmutable($data['date']), $bank->getKey(),
            Account::system('undeposited_funds')->getKey(),
            array_map(fn ($r) => Money::of($r, $currency), $data['receipts']),
            isset($data['fee']) ? Money::of($data['fee'], $currency) : null,
            Account::system('bank_charges')->getKey(),
            $data['slip_number'] ?? null,
        );
        $entry = $this->posting->post($draft, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'deposit_made', 'bank_account', $bank->getKey(), null,
            ['slip' => $data['slip_number'] ?? null, 'receipts' => count($data['receipts']), 'fee' => $data['fee'] ?? '0', 'journal_entry' => $entry->number], userId: $request->user()->getKey());

        return response()->json($entry, 201);
    }

    /**
     * One of the company's own active money accounts (loaded through the company-scoped model, CLAUDE.md rule 4); in the
     * company's currency unless the caller handles foreign currencies (transfers do, at the day's rate).
     */
    private function moneyAccount(string $id, string $field, array $kinds = ['bank', 'mobile_money', 'cash'], bool $baseOnly = true): Account
    {
        $account = Account::query()->where('is_active', true)->whereIn('subtype', $kinds)->find($id);
        if ($account === null) {
            throw ValidationException::withMessages([$field => ['Choose an active ' . implode(', ', str_replace('_', ' ', $kinds)) . ' account of this company.']]);
        }
        if ($baseOnly && $account->currency !== null && $account->currency !== $this->current->get()->base_currency) {
            throw ValidationException::withMessages([$field => ["{$account->name} is kept in {$account->currency}: deposit into it with a payment in {$account->currency}, or transfer to it."]]);
        }

        return $account;
    }
}
