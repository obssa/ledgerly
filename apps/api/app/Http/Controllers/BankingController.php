<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\BankingPostingBuilder;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bank deposits and transfers between the company's own bank, wallet and cash accounts. */
final class BankingController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly BankingPostingBuilder $builder,
        private readonly PostingService $posting,
    ) {
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.transfer');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'from_account_id' => ['required', 'uuid', 'different:to_account_id'],
            'to_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
        $currency = $this->current->get()->base_currency;

        $draft = $this->builder->transfer(
            $this->current->id(), new DateTimeImmutable($data['date']), $data['from_account_id'], $data['to_account_id'],
            Money::of($data['amount'], $currency),
            isset($data['fee']) ? Money::of($data['fee'], $currency) : null,
            Account::system('bank_charges')->getKey(),
            $data['memo'] ?? null,
        );

        return response()->json($this->posting->post($draft, $request->user()->getKey()), 201);
    }

    public function deposit(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.deposit');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['required', 'uuid'],
            'slip_number' => ['nullable', 'string', 'max:40'],
            'receipts' => ['required', 'array', 'min:1', 'max:500'],
            'receipts.*' => ['required', 'decimal:0,2', 'gt:0'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);
        $currency = $this->current->get()->base_currency;

        $draft = $this->builder->deposit(
            $this->current->id(), new DateTimeImmutable($data['date']), $data['bank_account_id'],
            Account::system('undeposited_funds')->getKey(),
            array_map(fn ($r) => Money::of($r, $currency), $data['receipts']),
            isset($data['fee']) ? Money::of($data['fee'], $currency) : null,
            Account::system('bank_charges')->getKey(),
            $data['slip_number'] ?? null,
        );

        return response()->json($this->posting->post($draft, $request->user()->getKey()), 201);
    }
}
