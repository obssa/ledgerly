<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Dimension;
use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A manual journal checked and drafted the same way whether it posts at once or after approval: balanced lines in the
 * company's currency, on its own active accounts, never on accounts kept by documents (032), with active dimensions.
 */
final class ManualJournal
{
    /** Kinds and system accounts a manual journal leaves alone (032 checks the same in the database). */
    public const KEPT_KINDS = ['receivable', 'payable', 'inventory'];

    public const KEPT_KEYS = ['accounts_receivable', 'accounts_payable', 'inventory', 'grni', 'undeposited_funds', 'retained_earnings'];

    /** @return array<string, list<string>> validation rules for a journal's date, memo and lines */
    public static function rules(string $today): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'memo' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.location_id' => ['nullable', 'uuid'],
            'lines.*.class_id' => ['nullable', 'uuid'],
            'lines.*.project_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * The draft of the journal, every line checked (accounts and dimensions loaded through the company scope).
     *
     * @param list<array<string, mixed>> $lines
     *
     * @throws ValidationException naming the lines that are wrong
     */
    public static function draft(Company $company, string $date, string $memo, array $lines, ?string $sourceId = null): JournalDraft
    {
        $base = $company->base_currency;
        $lines = array_values($lines);
        $accounts = Account::query()->whereIn('id', array_unique(array_column($lines, 'account_id')))->get()->keyBy('id');
        $errors = [];
        foreach ($lines as $i => $line) {
            $account = $accounts[$line['account_id'] ?? ''] ?? null;
            $debit = Money::of($line['debit'] ?? '0', $base);
            $credit = Money::of($line['credit'] ?? '0', $base);
            if ($account === null || ! $account->is_active) {
                $errors["lines.{$i}.account_id"] = ['Choose an active account of this company.'];
            } elseif (in_array($account->subtype, self::KEPT_KINDS, true) || in_array($account->system_key, self::KEPT_KEYS, true)) {
                $errors["lines.{$i}.account_id"] = ["{$account->code} {$account->name} is kept by its documents: use a credit note, a stock adjustment or a deposit instead."];
            } elseif ($account->currency !== null && $account->currency !== $base) {
                $errors["lines.{$i}.account_id"] = ["{$account->code} {$account->name} is kept in {$account->currency}: move money in it with a transfer."];
            }
            if ($debit->isZero() === $credit->isZero()) {
                $errors["lines.{$i}.debit"] = ['A line is a debit or a credit, above zero.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $dimensions = self::activeDimensions($lines);

        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $base, '1', $memo, 'manual', $sourceId);
        foreach ($lines as $i => $line) {
            $debit = Money::of($line['debit'] ?? '0', $base);
            $draft->add($debit->isZero()
                ? JournalLineDraft::credit($line['account_id'], Money::of($line['credit'], $base), null, $line['description'] ?? null, null, $dimensions[$i])
                : JournalLineDraft::debit($line['account_id'], $debit, null, $line['description'] ?? null, null, $dimensions[$i]));
        }
        if (! $draft->totalDebit()->equals($draft->totalCredit())) {
            throw ValidationException::withMessages(['lines' => ["Debits ({$draft->totalDebit()->toDecimalString()}) and credits ({$draft->totalCredit()->toDecimalString()}) must be equal."]]);
        }

        return $draft;
    }

    /** The approval limit for manual journals (base currency), or null when the company has none. */
    public static function approvalLimit(Company $company): ?Money
    {
        $limit = $company->settings['approval']['journal_above'] ?? null;

        return $limit === null || $limit === '' ? null : Money::of((string) $limit, $company->base_currency);
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, string>> each line's active location, class and project */
    private static function activeDimensions(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach (Dimension::COLUMNS as $column) {
                if (! empty($line[$column])) {
                    $ids[] = $line[$column];
                }
            }
        }
        $found = Dimension::query()->whereIn('id', array_unique($ids))->where('is_active', true)->get(['id', 'kind'])->keyBy('id');
        $out = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            $out[$i] = [];
            foreach (Dimension::COLUMNS as $kind => $column) {
                if (empty($line[$column])) {
                    continue;
                }
                if (($found[$line[$column]]->kind ?? null) !== $kind) {
                    $errors["lines.{$i}.{$column}"] = ["Not an active {$kind} of this company."];
                } else {
                    $out[$i][$column] = $line[$column];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
