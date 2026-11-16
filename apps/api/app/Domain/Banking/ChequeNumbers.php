<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Sales\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Cheque numbers (029). A cheque the company writes has a number from the account's cheque book, used once: a
 * cancelled cheque keeps its number. A cheque received may carry the payer's number, which is theirs, not checked.
 */
final class ChequeNumbers
{
    public const RULE = ['nullable', 'string', 'regex:/^[0-9A-Za-z-]{1,20}$/'];

    /** @param array<string, mixed> $data a validated payment request */
    public static function assertWritable(array $data): void
    {
        if (($data['method'] ?? null) !== 'cheque') {
            return;
        }
        $number = $data['cheque_number'] ?? null;
        if ($number === null || $number === '') {
            throw ValidationException::withMessages(['cheque_number' => ['Enter the number of the cheque from the cheque book.']]);
        }
        $used = Payment::query()->where(['direction' => 'out', 'account_id' => $data['account_id'] ?? null, 'cheque_number' => $number])->first(['payment_date', 'status']);
        if ($used !== null) {
            throw ValidationException::withMessages(['cheque_number' => ["Cheque {$number} of this account was already written on {$used->payment_date}" . ($used->status === 'reversed' ? ' and cancelled' : '') . '.']]);
        }
    }

    /**
     * The number after the highest numbered cheque written on the account, keeping its leading zeros (000101 -> 000102);
     * null when none is numbered yet.
     */
    public static function next(string $accountId): ?string
    {
        $numbers = Payment::query()->where(['direction' => 'out', 'account_id' => $accountId])->whereNotNull('cheque_number')->pluck('cheque_number')
            ->filter(fn ($n) => ctype_digit((string) $n));
        if ($numbers->isEmpty()) {
            return null;
        }
        $top = $numbers->sortBy(fn ($n) => [strlen((string) $n), (string) $n])->last();

        return str_pad((string) ((int) $top + 1), strlen((string) $top), '0', STR_PAD_LEFT);
    }
}
