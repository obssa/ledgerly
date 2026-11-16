<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reads a bank, wallet or cash statement exported as CSV (comma, semicolon or tab separated). Banks name their columns
 * differently, so the header row is found and read by meaning: a date, a description, and either one signed amount or
 * separate money-out and money-in columns; a reference and a running balance when there are any. Lines before the header
 * (the bank's letterhead, the account number) and lines without an amount (balance brought forward, totals) are skipped.
 *
 * Nothing is stored here and the file itself is never kept: the result is the list of lines to import, each with a stable
 * id built from what the bank said, so importing the same statement twice adds nothing the second time.
 */
final class StatementCsv
{
    public const MAX_LINES = 5000;

    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd/m/y', 'd-m-y', 'd M Y', 'd-M-Y', 'd M y', 'd-M-y', 'j M Y', 'M j, Y', 'j F Y'];

    /** Column meanings, checked in this order: "Debit amount" is money out before it is an amount; "Value date" is a date. */
    private const COLUMNS = [
        'balance' => '/\b(balance|running balance)\b/',
        'out' => '/\b(debit|debits|withdrawal|withdrawals|money out|paid out|dr)\b/',
        'in' => '/\b(credit|credits|deposit|deposits|money in|paid in|cr)\b/',
        'date' => '/\b(date|posted|posting|transaction time|time)\b/',
        'amount' => '/\b(amount|value|amt)\b/',
        'reference' => '/\b(ref|reference|transaction id|transaction no|txn id|txn no|trx id|trx no|receipt|receipt no|cheque|cheque no|check no|ft|id)\b/',
        'description' => '/\b(description|narration|narrative|details|detail|particulars|remarks|remark|memo|payee|purpose)\b/',
        'type' => '/\b(transaction type|type)\b/',
    ];

    /**
     * @return array{columns: array<string, string>, lines: list<array{row: int, posted_on: string, description: string, amount: string, reference: ?string, balance_after: ?string, external_id: string}>}
     *
     * @throws ValidationException naming the rows that could not be read
     */
    public function parse(string $csv, string $today): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $rows = $this->rows($csv);

        [$headerAt, $columns] = $this->header($rows);
        $errors = [];
        $lines = [];
        $seen = [];
        foreach (array_slice($rows, $headerAt + 1, null, true) as $i => $cells) {
            $rowNo = $i + 1;
            $cell = fn (string $kind) => isset($columns[$kind]) ? trim((string) ($cells[$columns[$kind]] ?? '')) : '';
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            try {
                $amount = $this->amountOf($cell('amount'), $cell('in'), $cell('out'));
            } catch (MathException) {
                $errors[] = "Row {$rowNo}: the amount is not a number with at most 2 decimals.";
                continue;
            }
            $date = $this->dateOf($cell('date'));
            if ($amount === null || $amount->isZero()) {
                continue;   // balance brought forward, a total, a blank line
            }
            if ($date === null) {
                $errors[] = "Row {$rowNo}: \"{$this->shorten($cell('date'))}\" is not a date we can read (use 2026-11-05 or 05/11/2026).";
                continue;
            }
            if ($date > $today) {
                $errors[] = "Row {$rowNo}: dated {$date}, after today.";
                continue;
            }
            try {
                $balance = $cell('balance') === '' ? null : $this->decimal($cell('balance'));
            } catch (MathException) {
                $balance = null;   // a running balance we cannot read is not worth refusing the statement over
            }
            $reference = $cell('reference') !== '' ? mb_substr($cell('reference'), 0, 100) : null;
            $description = $cell('description') !== '' ? $cell('description') : ($cell('type') !== '' ? $cell('type') : ($reference ?? 'Statement line'));
            $amountText = (string) $amount->toScale(2);

            $key = hash('sha256', implode('|', [$date, $amountText, $description, $reference ?? '', $balance === null ? '' : (string) $balance->toScale(2)]));
            $seen[$key] = ($seen[$key] ?? 0) + 1;   // the same line twice in one statement is two lines
            $lines[] = [
                'row' => $rowNo, 'posted_on' => $date, 'description' => mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 500),
                'amount' => $amountText, 'reference' => $reference, 'balance_after' => $balance === null ? null : (string) $balance->toScale(2),
                'external_id' => 'csv:' . substr($key, 0, 40) . ($seen[$key] > 1 ? '#' . $seen[$key] : ''),
            ];
            if (count($lines) > self::MAX_LINES) {
                throw ValidationException::withMessages(['csv' => ['A statement can have at most ' . self::MAX_LINES . ' lines: split it by month.']]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['csv' => array_slice($errors, 0, 20)]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['csv' => ['The statement has no lines with an amount.']]);
        }

        return ['columns' => array_map(fn (int $i) => (string) ($rows[$headerAt][$i] ?? ''), $columns), 'lines' => $lines];
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $sample = implode("\n", array_slice(preg_split('/\R/', $csv, 25) ?: [], 0, 20));
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($sample, $d))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => (string) $c, $cells);
            if (count($rows) > self::MAX_LINES + 50) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }

    /** @return array{0: int, 1: array<string, int>} the header row and which column holds what */
    private function header(array $rows): array
    {
        foreach (array_slice($rows, 0, 30, true) as $i => $cells) {
            $columns = [];
            foreach ($cells as $c => $name) {
                $name = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $name)));
                if ($name === '') {
                    continue;
                }
                // A column means the first thing it matches; a second "Value date" is not the amount.
                foreach (self::COLUMNS as $kind => $pattern) {
                    if (preg_match($pattern, $name)) {
                        $columns[$kind] ??= $c;
                        break;
                    }
                }
            }
            if (isset($columns['date']) && (isset($columns['amount']) || isset($columns['in']) || isset($columns['out']))) {
                return [$i, $columns];
            }
        }

        throw ValidationException::withMessages(['csv' => ['No header row found: the statement needs a date column and an amount column (or money in and money out columns).']]);
    }

    private function amountOf(string $amount, string $in, string $out): ?BigDecimal
    {
        if ($amount !== '') {
            // One amount with a "Dr/Cr" flag column beside it.
            $flag = strtoupper(trim($out !== '' ? $out : $in));
            $number = $this->decimal($amount);

            return match (true) {
                in_array($flag, ['DR', 'D', 'DEBIT'], true) => $number->abs()->negated(),
                in_array($flag, ['CR', 'C', 'CREDIT'], true) => $number->abs(),
                default => $number,
            };
        }
        if ($in === '' && $out === '') {
            return null;
        }
        $in = $in === '' ? BigDecimal::zero() : $this->decimal($in)->abs();
        $out = $out === '' ? BigDecimal::zero() : $this->decimal($out)->abs();

        return $in->minus($out);
    }

    /** "1,250.50", "-1250.5", "(1,250.50)", "ETB 1,250.50", "1,250.50 DR" */
    private function decimal(string $value): BigDecimal
    {
        $v = strtoupper(trim($value));
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            [$negative, $v] = [true, $m[1]];
        }
        if (preg_match('/^(.*?)\s*(DR|CR)$/', $v, $m)) {
            [$negative, $v] = [$negative || $m[2] === 'DR', $m[1]];
        }
        $v = (string) preg_replace('/^(ETB|BIRR|BR|USD|EUR|KES)\.?\s*|\s*(ETB|BIRR|BR|USD|EUR|KES)$|[\s,]/', '', $v);
        $number = BigDecimal::of($v);
        if ($number->getScale() > 2) {
            $number = $number->toScale(2);   // throws when the extra digits are not zeros
        }

        return $negative ? $number->abs()->negated() : $number;
    }

    private function dateOf(string $value): ?string
    {
        $value = trim((string) preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?(\s?[AP]M)?(Z|[+-]\d{2}:?\d{2})?$/i', '', trim($value)));
        if ($value === '') {
            return null;
        }
        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $problems = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))
                && (int) $date->format('Y') >= 1900) {   // "05-11-26" is not the year 26
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function shorten(string $value): string
    {
        return mb_substr($value, 0, 30);
    }
}
