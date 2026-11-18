<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 1: customers, suppliers, items and accounts brought in from a CSV file (a spreadsheet saved as CSV), and the
 * opening trial balance (openingBalances, below). Each row goes through
 * the same endpoint as adding one by hand, so the same rules, checks and audit apply. "Check" runs every row and keeps
 * nothing; an import keeps all the rows or none: one refused row and nothing is added. The file is read, never stored.
 */
final class ImportController extends Controller
{
    private const MAX_ROWS = 2000;

    /** kind => [controller, permission, columns it takes (heading => field), numeric fields] */
    private const KINDS = [
        'customers' => [CustomerController::class, 'contacts.create'],
        'suppliers' => [SupplierController::class, 'contacts.create'],
        'items' => [ItemController::class, 'inventory.items'],
        'accounts' => [ChartController::class, 'accounting.chart'],
    ];

    /** The headings each kind reads (lower case, spaces as underscores), with a few common other names. */
    private const COLUMNS = [
        'customers' => ['name' => 'name', 'tax_id' => 'tax_id', 'tin' => 'tax_id', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
            'currency' => 'currency', 'payment_terms_days' => 'payment_terms_days', 'terms' => 'payment_terms_days', 'credit_limit' => 'credit_limit',
            'notes' => 'notes', 'address' => 'address.line1', 'address_line1' => 'address.line1', 'address_line2' => 'address.line2',
            'city' => 'address.city', 'region' => 'address.region', 'postal_code' => 'address.postal_code'],
        'suppliers' => ['name' => 'name', 'tax_id' => 'tax_id', 'tin' => 'tax_id', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
            'currency' => 'currency', 'payment_terms_days' => 'payment_terms_days', 'terms' => 'payment_terms_days', 'notes' => 'notes',
            'address' => 'address.line1', 'address_line1' => 'address.line1', 'address_line2' => 'address.line2', 'city' => 'address.city',
            'region' => 'address.region', 'postal_code' => 'address.postal_code'],
        'items' => ['name' => 'name', 'sku' => 'sku', 'code' => 'sku', 'type' => 'type', 'unit' => 'unit', 'sale_price' => 'sale_price', 'price' => 'sale_price',
            'purchase_price' => 'purchase_price', 'cost' => 'purchase_price', 'reorder_point' => 'reorder_point', 'authority_item_code' => 'authority_item_code'],
        // The chart: a parent is named by its code, above its accounts in the file.
        'accounts' => ['code' => 'code', 'account_code' => 'code', 'number' => 'code', 'account_number' => 'code', 'name' => 'name', 'account_name' => 'name',
            'account' => 'name', 'type' => 'type', 'account_type' => 'type', 'kind' => 'kind', 'parent' => 'parent', 'parent_code' => 'parent'],
    ];

    /** Opening balances: one line per account, by its code, as a debit and a credit or one signed balance (debit positive). */
    private const OPENING_COLUMNS = ['code' => 'code', 'account_code' => 'code', 'account' => 'code', 'number' => 'code', 'account_number' => 'code',
        'debit' => 'debit', 'dr' => 'debit', 'credit' => 'credit', 'cr' => 'credit', 'balance' => 'balance', 'amount' => 'balance', 'name' => 'name', 'account_name' => 'name'];

    /** The words a spreadsheet uses for the five account types. */
    private const TYPES = ['asset' => 'asset', 'assets' => 'asset', 'liability' => 'liability', 'liabilities' => 'liability', 'equity' => 'equity',
        'capital' => 'equity', 'income' => 'income', 'revenue' => 'income', 'revenues' => 'income', 'sales' => 'income', 'expense' => 'expense',
        'expenses' => 'expense', 'cost' => 'expense', 'costs' => 'expense'];

    private const NUMERIC = ['payment_terms_days', 'credit_limit', 'sale_price', 'purchase_price', 'reorder_point'];

    public function store(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        [$controller, $permission] = self::KINDS[$kind];
        $this->authorizeCompany($permission);
        $data = $request->validate(['csv' => ['required', 'string', 'max:900000'], 'check' => ['sometimes', 'boolean']]);
        $check = (bool) ($data['check'] ?? false);

        [$headings, $rows] = $this->parse($data['csv']);
        $columns = self::COLUMNS[$kind];
        $used = array_values(array_filter($headings, fn ($h) => isset($columns[$h])));
        $fieldsUsed = array_map(fn ($h) => $columns[$h], $used);
        if (! in_array('name', $fieldsUsed, true) || ($kind === 'accounts' && count(array_intersect(['code', 'type'], $fieldsUsed)) < 2)) {
            throw ValidationException::withMessages(['csv' => [$kind === 'accounts'
                ? 'The first line names the columns: "Code", "Name" and "Type" at least.' : 'The first line names the columns, and one of them is "Name".']]);
        }
        if (count($rows) === 0 || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => ['A file has 1 to ' . self::MAX_ROWS . ' rows below its headings.']]);
        }

        $errors = [];
        $added = 0;
        $skipped = [];
        DB::beginTransaction();   // a savepoint inside the request's own transaction
        try {
            foreach ($rows as $n => $cells) {
                $fields = [];
                foreach ($headings as $i => $h) {
                    $value = trim((string) ($cells[$i] ?? ''));
                    if (! isset($columns[$h]) || $value === '') {
                        continue;
                    }
                    $field = $columns[$h];
                    if (in_array($field, self::NUMERIC, true)) {
                        $value = str_replace([',', ' '], '', $value);
                    }
                    data_set($fields, $field, $field === 'type' ? strtolower(str_replace([' ', '-'], '_', $value)) : $value);
                }
                if ($kind === 'items' && ! isset($fields['type'])) {
                    $fields['type'] = 'service';
                }
                if ($kind === 'accounts') {
                    $fields = $this->accountRow($fields);
                    if (isset($fields['code']) && Account::query()->where('code', $fields['code'])->exists()) {
                        $skipped[] = $fields['code'];   // already in the chart (the country pack's, or an earlier import)
                        continue;
                    }
                    if (isset($fields['error'])) {
                        $errors[] = ['row' => $n + 2, 'name' => $fields['name'] ?? null, 'messages' => [$fields['error']]];
                        continue;
                    }
                }
                $sub = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($fields, JSON_THROW_ON_ERROR));
                $sub->setUserResolver(fn () => $request->user());
                try {
                    app()->call([app($controller), 'store'], ['request' => $sub]);
                    $added++;
                } catch (ValidationException $e) {
                    $errors[] = ['row' => $n + 2, 'name' => $fields['name'] ?? null, 'messages' => array_values(array_unique(array_merge(...array_values($e->errors()))))];
                    if (count($errors) >= 100) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $keep = ! $check && $errors === [];
        $keep ? DB::commit() : DB::rollBack();

        return response()->json([
            'kind' => $kind, 'checked' => $check, 'rows' => count($rows), 'added' => $keep ? $added : 0, 'errors' => $errors, 'skipped' => $skipped,
            'columns' => array_values(array_unique(array_map(fn ($h) => $columns[$h], $used))),
            'ignored_columns' => array_values(array_filter($headings, fn ($h) => $h !== '' && ! isset($columns[$h]))),
        ], $keep ? 201 : 200);
    }

    /**
     * Opening balances from a trial balance saved as CSV: each line names an account by its code, with a debit and a
     * credit or one signed balance. They go through PUT opening-balances as one set (the same checks, one entry at the
     * conversion date, any difference to opening balance equity), replacing the balances entered before.
     */
    public function openingBalances(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.opening');
        $data = $request->validate(['csv' => ['required', 'string', 'max:900000'], 'check' => ['sometimes', 'boolean']]);
        $check = (bool) ($data['check'] ?? false);

        [$headings, $rows] = $this->parse($data['csv']);
        $columns = self::OPENING_COLUMNS;
        $used = array_values(array_filter($headings, fn ($h) => isset($columns[$h])));
        $fields = array_map(fn ($h) => $columns[$h], $used);
        if (! in_array('code', $fields, true) || (! in_array('balance', $fields, true) && array_intersect(['debit', 'credit'], $fields) === [])) {
            throw ValidationException::withMessages(['csv' => ['The first line names the columns: "Code" and "Debit" and "Credit", or "Code" and "Balance".']]);
        }
        if (count($rows) === 0 || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => ['A file has 1 to ' . self::MAX_ROWS . ' rows below its headings.']]);
        }

        $errors = [];
        $lines = [];
        $rowOf = [];   // line index => file row
        foreach ($rows as $n => $cells) {
            $row = [];
            foreach ($headings as $i => $h) {
                if (isset($columns[$h])) {
                    $row[$columns[$h]] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $amount = fn (string $f) => isset($row[$f]) && $row[$f] !== '' ? str_replace([',', ' '], '', $row[$f]) : null;
            [$debit, $credit, $balance] = [$amount('debit'), $amount('credit'), $amount('balance')];
            foreach (['debit' => $debit, 'credit' => $credit, 'balance' => $balance] as $f => $v) {
                if ($v !== null && ! preg_match('/^-?\d{1,18}(\.\d{1,2})?$/', $v)) {
                    $errors[] = ['row' => $n + 2, 'name' => $row['code'] ?? null, 'messages' => ["The {$f} is not an amount with up to 2 decimals."]];
                    continue 2;
                }
            }
            if ($balance !== null) {
                [$debit, $credit] = str_starts_with($balance, '-') ? [null, ltrim($balance, '-')] : [$balance, null];
            }
            if (bccomp($debit ?? '0', '0', 2) === 0 && bccomp($credit ?? '0', '0', 2) === 0) {
                continue;   // an account with no balance
            }
            $account = ($row['code'] ?? '') === '' ? null : Account::query()->where('code', $row['code'])->first(['id']);
            if ($account === null) {
                $errors[] = ['row' => $n + 2, 'name' => $row['code'] ?? null, 'messages' => ['No account with this code in the chart.']];
                continue;
            }
            $rowOf[count($lines)] = $n + 2;
            $lines[] = ['account_id' => $account->getKey(), 'debit' => $debit, 'credit' => $credit];
        }

        $added = 0;
        if ($errors === [] && $lines === []) {
            $errors[] = ['row' => 2, 'name' => null, 'messages' => ['No account in the file has a balance.']];
        }
        if ($errors === []) {
            DB::beginTransaction();   // a savepoint: a check keeps nothing
            try {
                $sub = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['lines' => $lines], JSON_THROW_ON_ERROR));
                $sub->setUserResolver(fn () => $request->user());
                app()->call([app(OpeningBalanceController::class), 'update'], ['request' => $sub]);
                $added = count($lines);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    $line = preg_match('/^lines\.(\d+)\./', $key, $m) ? (int) $m[1] : null;
                    $errors[] = ['row' => $line !== null ? ($rowOf[$line] ?? 2) : 2, 'name' => null, 'messages' => array_values($messages)];
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $check || $errors !== [] ? DB::rollBack() : DB::commit();
        }
        $keep = ! $check && $errors === [];

        return response()->json([
            'kind' => 'opening-balances', 'checked' => $check, 'rows' => count($rows), 'added' => $keep ? $added : 0, 'errors' => $errors, 'skipped' => [],
            'columns' => array_values(array_unique($fields)),
            'ignored_columns' => array_values(array_filter($headings, fn ($h) => $h !== '' && ! isset($columns[$h]))),
        ], $keep ? 201 : 200);
    }

    /**
     * An account row as POST accounts takes it: the type from the words a spreadsheet uses, the kind as a key, and the
     * parent's code turned into its id (the company's own account; listed above in the file, or already in the chart).
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function accountRow(array $fields): array
    {
        $key = fn (string $v) => strtolower(trim(preg_replace('/[\s-]+/', '_', $v) ?? ''));
        if (isset($fields['type'])) {
            $fields['type'] = self::TYPES[$key($fields['type'])] ?? $key($fields['type']);
        }
        if (isset($fields['kind'])) {
            $fields['kind'] = $key($fields['kind']);
        }
        if (isset($fields['parent'])) {
            $parent = Account::query()->where('code', $fields['parent'])->value('id');
            if ($parent === null) {
                $fields['error'] = "Parent {$fields['parent']} is not in the chart: list a parent above its accounts.";
            } else {
                $fields['parent_id'] = (string) $parent;
            }
            unset($fields['parent']);
        }

        return $fields;
    }

    /** @return array{0: list<string>, 1: list<list<string>>} headings (lower case, spaces as underscores) and the rows below */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\x{FEFF}/u', '', $csv) ?? $csv;   // a spreadsheet's byte order mark
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $first = fgets($stream) ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($stream);
        $headings = null;
        $rows = [];
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || array_filter($cells, fn ($c) => trim((string) $c) !== '') === []) {
                continue;   // a blank line
            }
            if ($headings === null) {
                $headings = array_map(fn ($h) => trim(preg_replace('/[\s-]+/', '_', strtolower(trim((string) $h))) ?? '', '_'), $cells);
                continue;
            }
            $rows[] = array_map(fn ($c) => (string) $c, $cells);
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($stream);

        return [$headings ?? [], $rows];
    }
}
