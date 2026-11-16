<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\BankingPostingBuilder;
use App\Domain\Audit\AuditLogger;
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
    ) {
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.transfer');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'from_account_id' => ['required', 'uuid', 'different:to_account_id'],
            'to_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
        $currency = $this->current->get()->base_currency;
        $from = $this->moneyAccount($data['from_account_id'], 'from_account_id');
        $to = $this->moneyAccount($data['to_account_id'], 'to_account_id');

        $draft = $this->builder->transfer(
            $this->current->id(), new DateTimeImmutable($data['date']), $from->getKey(), $to->getKey(),
            Money::of($data['amount'], $currency),
            isset($data['fee']) ? Money::of($data['fee'], $currency) : null,
            Account::system('bank_charges')->getKey(),
            $data['memo'] ?? "Transfer from {$from->name} to {$to->name}",
        );
        $entry = $this->posting->post($draft, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'money_transferred', 'bank_account', $from->getKey(), null,
            ['to' => $to->name, 'amount' => $data['amount'], 'fee' => $data['fee'] ?? '0', 'journal_entry' => $entry->number], userId: $request->user()->getKey());

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
     * One of the company's own active money accounts (loaded through the company-scoped model, CLAUDE.md rule 4), in the
     * company's currency: foreign-currency transfers at the day's rate come later.
     */
    private function moneyAccount(string $id, string $field, array $kinds = ['bank', 'mobile_money', 'cash']): Account
    {
        $account = Account::query()->where('is_active', true)->whereIn('subtype', $kinds)->find($id);
        if ($account === null) {
            throw ValidationException::withMessages([$field => ['Choose an active ' . implode(', ', str_replace('_', ' ', $kinds)) . ' account of this company.']]);
        }
        if ($account->currency !== null && $account->currency !== $this->current->get()->base_currency) {
            throw ValidationException::withMessages([$field => ["{$account->name} is kept in {$account->currency}: transfers in another currency are not available yet."]]);
        }

        return $account;
    }
}
