<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 1: customers, suppliers and items brought in from a CSV file (a spreadsheet saved as CSV). Each row goes through
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
    ];

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
        if (! in_array('name', array_map(fn ($h) => $columns[$h], $used), true)) {
            throw ValidationException::withMessages(['csv' => ['The first line names the columns, and one of them is "Name".']]);
        }
        if (count($rows) === 0 || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => ['A file has 1 to ' . self::MAX_ROWS . ' rows below its headings.']]);
        }

        $errors = [];
        $added = 0;
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
            'kind' => $kind, 'checked' => $check, 'rows' => count($rows), 'added' => $keep ? $added : 0, 'errors' => $errors,
            'columns' => array_values(array_unique(array_map(fn ($h) => $columns[$h], $used))),
            'ignored_columns' => array_values(array_filter($headings, fn ($h) => $h !== '' && ! isset($columns[$h]))),
        ], $keep ? 201 : 200);
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
