<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\BankingPostingBuilder;
use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        } else {
            if ($fromCurrency !== $base && $toCurrency !== $base && $fromCurrency !== $toCurrency) {
                throw ValidationException::withMessages(['to_account_id' => ["{$from->name} is in {$fromCurrency} and {$to->name} in {$toCurrency}: move the money through a {$base} account."]]);
            }
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
