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

    /** kind => [controller, permission, the controller's action, the fields a file must have] */
    private const KINDS = [
        'customers' => [CustomerController::class, 'contacts.create', 'store', ['name']],
        'suppliers' => [SupplierController::class, 'contacts.create', 'store', ['name']],
        'items' => [ItemController::class, 'inventory.items', 'store', ['name']],
        'accounts' => [ChartController::class, 'accounting.chart', 'store', ['code', 'name', 'type']],
        // Open invoices and bills from the old books (057), each through POST opening-balances/invoices or bills.
        'open-invoices' => [OpeningBalanceController::class, 'accounting.opening', 'invoice', ['contact', 'number', 'issue_date', 'amount']],
        'open-bills' => [OpeningBalanceController::class, 'accounting.opening', 'bill', ['contact', 'reference', 'issue_date', 'amount']],
    ];

    /** How a missing required field is named to the person. */
    private const HEADINGS = ['name' => 'Name', 'code' => 'Code', 'type' => 'Type', 'contact' => 'Customer (or Supplier)', 'number' => 'Number',
        'reference' => 'Supplier invoice number', 'issue_date' => 'Date', 'amount' => 'Amount'];

    /** The headings each kind reads (lower case, spaces as underscores), with a few common other names. */
    private const COLUMNS = [
        // 096: with the headings of QuickBooks, Sage and Tally exports as well.
        'customers' => ['customer' => 'name', 'customer_name' => 'name', 'display_name' => 'name', 'full_name' => 'name', 'company_name' => 'name', 'ledger_name' => 'name', 'phone_number' => 'phone', 'telephone' => 'phone', 'mobile' => 'phone', 'e_mail' => 'email', 'email_address' => 'email', 'tax_registration_number' => 'tax_id', 'tax_number' => 'tax_id', 'vat_reg_no' => 'tax_id', 'vat_registration_number' => 'tax_id', 'vat_number' => 'tax_id', 'billing_address' => 'address.line1', 'billing_street' => 'address.line1', 'billing_city' => 'address.city', 'billing_state' => 'address.region', 'billing_zip' => 'address.postal_code', 'street1' => 'address.line1', 'street2' => 'address.line2', 'town' => 'address.city', 'county' => 'address.region', 'state' => 'address.region', 'post_code' => 'address.postal_code', 'postcode' => 'address.postal_code', 'pin_code' => 'address.postal_code', 'payment_terms' => 'payment_terms_days', 'credit_terms' => 'payment_terms_days', 'name' => 'name', 'tax_id' => 'tax_id', 'tin' => 'tax_id', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
            'currency' => 'currency', 'payment_terms_days' => 'payment_terms_days', 'terms' => 'payment_terms_days', 'credit_limit' => 'credit_limit',
            'notes' => 'notes', 'address' => 'address.line1', 'address_line1' => 'address.line1', 'address_line2' => 'address.line2',
            'city' => 'address.city', 'region' => 'address.region', 'postal_code' => 'address.postal_code'],
        'suppliers' => ['vendor' => 'name', 'vendor_name' => 'name', 'supplier' => 'name', 'supplier_name' => 'name', 'display_name' => 'name', 'full_name' => 'name', 'company_name' => 'name', 'ledger_name' => 'name', 'phone_number' => 'phone', 'telephone' => 'phone', 'mobile' => 'phone', 'e_mail' => 'email', 'email_address' => 'email', 'tax_registration_number' => 'tax_id', 'tax_number' => 'tax_id', 'vat_reg_no' => 'tax_id', 'vat_registration_number' => 'tax_id', 'vat_number' => 'tax_id', 'billing_address' => 'address.line1', 'billing_street' => 'address.line1', 'billing_city' => 'address.city', 'billing_state' => 'address.region', 'billing_zip' => 'address.postal_code', 'street1' => 'address.line1', 'street2' => 'address.line2', 'town' => 'address.city', 'county' => 'address.region', 'state' => 'address.region', 'post_code' => 'address.postal_code', 'postcode' => 'address.postal_code', 'pin_code' => 'address.postal_code', 'payment_terms' => 'payment_terms_days', 'credit_terms' => 'payment_terms_days', 'name' => 'name', 'tax_id' => 'tax_id', 'tin' => 'tax_id', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
            'currency' => 'currency', 'payment_terms_days' => 'payment_terms_days', 'terms' => 'payment_terms_days', 'notes' => 'notes',
            'address' => 'address.line1', 'address_line1' => 'address.line1', 'address_line2' => 'address.line2', 'city' => 'address.city',
            'region' => 'address.region', 'postal_code' => 'address.postal_code'],
        'items' => ['product/service_name' => 'name', 'product/service' => 'name', 'item_name' => 'name', 'item' => 'name', 'stock_item' => 'name', 'item_code' => 'sku', 'stock_code' => 'sku', 'sales_price_/_rate' => 'sale_price', 'sales_price' => 'sale_price', 'selling_price' => 'sale_price', 'rate' => 'sale_price', 'purchase_cost' => 'purchase_price', 'cost_price' => 'purchase_price', 'item_type' => 'type', 'name' => 'name', 'sku' => 'sku', 'code' => 'sku', 'type' => 'type', 'unit' => 'unit', 'sale_price' => 'sale_price', 'price' => 'sale_price',
            'purchase_price' => 'purchase_price', 'cost' => 'purchase_price', 'reorder_point' => 'reorder_point', 'authority_item_code' => 'authority_item_code'],
        // The chart: a parent is named by its code, above its accounts in the file.
        'accounts' => ['code' => 'code', 'account_code' => 'code', 'number' => 'code', 'account_number' => 'code', 'name' => 'name', 'account_name' => 'name',
            'account' => 'name', 'type' => 'type', 'account_type' => 'type', 'account_#' => 'code', 'account_no' => 'code', 'a/c' => 'code', 'ref' => 'code', 'nominal_code' => 'code', 'category' => 'type', 'group' => 'type', 'under' => 'type', 'kind' => 'kind', 'parent' => 'parent', 'parent_code' => 'parent'],
        // A customer or supplier is named as in Ledgerly; dates as 2026-06-30 or 30/06/2026; the amount is what was still open.
        'open-invoices' => ['customer' => 'contact', 'customer_name' => 'contact', 'name' => 'contact', 'number' => 'number', 'invoice_number' => 'number',
            'invoice' => 'number', 'date' => 'issue_date', 'issue_date' => 'issue_date', 'invoice_date' => 'issue_date', 'due_date' => 'due_date', 'due' => 'due_date',
            'currency' => 'currency', 'amount' => 'amount', 'open_amount' => 'amount', 'amount_due' => 'amount', 'balance' => 'amount', 'outstanding' => 'amount',
            'rate' => 'fx_rate', 'fx_rate' => 'fx_rate', 'exchange_rate' => 'fx_rate'],
        'open-bills' => ['supplier' => 'contact', 'supplier_name' => 'contact', 'name' => 'contact', 'reference' => 'reference', 'supplier_invoice' => 'reference',
            'supplier_invoice_number' => 'reference', 'invoice_number' => 'reference', 'number' => 'number', 'our_number' => 'number',
            'date' => 'issue_date', 'issue_date' => 'issue_date', 'bill_date' => 'issue_date', 'due_date' => 'due_date', 'due' => 'due_date',
            'currency' => 'currency', 'amount' => 'amount', 'open_amount' => 'amount', 'amount_due' => 'amount', 'balance' => 'amount', 'outstanding' => 'amount',
            'rate' => 'fx_rate', 'fx_rate' => 'fx_rate', 'exchange_rate' => 'fx_rate'],
    ];

    /** Opening balances: one line per account, by its code, as a debit and a credit or one signed balance (debit positive). */
    private const OPENING_COLUMNS = ['code' => 'code', 'account_code' => 'code', 'account' => 'code', 'number' => 'code', 'account_number' => 'code',
        'debit' => 'debit', 'dr' => 'debit', 'credit' => 'credit', 'cr' => 'credit', 'balance' => 'balance', 'amount' => 'balance', 'name' => 'name', 'account_name' => 'name'];

    /** Module 6: the columns of a journal file; rows with the same date and reference are one entry. */
    private const JOURNAL_COLUMNS = ['date' => 'date', 'entry_date' => 'date', 'reference' => 'reference', 'ref' => 'reference', 'journal' => 'reference',
        'entry' => 'reference', 'journal_number' => 'reference', 'memo' => 'memo', 'narration' => 'memo', 'code' => 'code', 'account' => 'code',
        'account_code' => 'code', 'account_number' => 'code', 'debit' => 'debit', 'dr' => 'debit', 'credit' => 'credit', 'cr' => 'credit',
        'description' => 'description', 'line_description' => 'description'];

    /** At most this many entries in one file. */
    private const MAX_JOURNALS = 200;

    /** The words a spreadsheet uses for the five account types. */
    private const TYPES = ['asset' => 'asset', 'assets' => 'asset', 'liability' => 'liability', 'liabilities' => 'liability', 'equity' => 'equity',
        'capital' => 'equity', 'income' => 'income', 'revenue' => 'income', 'revenues' => 'income', 'sales' => 'income', 'expense' => 'expense',
        'expenses' => 'expense', 'cost' => 'expense', 'costs' => 'expense'];

    /**
     * 096: the account types (and groups) other books use, as the type and kind they are here; null where the row is
     * left out, and why. Bank, cash and wallet accounts are opened under Banking with their details; the receivable and
     * payable are the one control account each that the documents keep.
     *
     * @var array<string, array{0: ?string, 1: ?string, 2?: string}>
     */
    private const FOREIGN_TYPES = [
        // QuickBooks
        'bank' => [null, null, 'a bank account: open it under Banking'], 'accounts_receivable_(a/r)' => [null, null, 'the receivable: Ledgerly keeps one, from invoices'],
        'accounts_receivable' => [null, null, 'the receivable: Ledgerly keeps one, from invoices'], 'other_current_assets' => ['asset', null], 'fixed_assets' => ['asset', 'fixed_asset'],
        'other_assets' => ['asset', null], 'accounts_payable_(a/p)' => [null, null, 'the payable: Ledgerly keeps one, from bills'],
        'accounts_payable' => [null, null, 'the payable: Ledgerly keeps one, from bills'], 'credit_card' => ['liability', null], 'other_current_liabilities' => ['liability', null],
        'long_term_liabilities' => ['liability', 'loan'], 'other_income' => ['income', 'other_income'], 'cost_of_goods_sold' => ['expense', 'cogs'],
        'other_expense' => ['expense', 'operating'],
        // Sage
        'purchases' => ['expense', 'cogs'], 'direct_expenses' => ['expense', 'cogs'], 'overheads' => ['expense', 'operating'], 'current_assets' => ['asset', null],
        'current_liabilities' => ['liability', null], 'capital_&_reserves' => ['equity', null], 'cost_of_sales' => ['expense', 'cogs'],
        // Tally groups
        'bank_accounts' => [null, null, 'a bank account: open it under Banking'], 'bank_od_a/c' => [null, null, 'a bank account: open it under Banking'],
        'cash_in_hand' => [null, null, 'a cash account: open it under Banking'], 'sundry_debtors' => [null, null, 'a customer: import it as a customer'],
        'sundry_creditors' => [null, null, 'a supplier: import it as a supplier'], 'investments' => ['asset', null], 'deposits_(asset)' => ['asset', 'deposit'],
        'loans_&_advances_(asset)' => ['asset', null], 'stock_in_hand' => [null, null, 'stock: it comes from the items and their opening quantities'],
        'duties_&_taxes' => ['liability', 'tax_payable'], 'provisions' => ['liability', 'accrual'], 'loans_(liability)' => ['liability', 'loan'],
        'secured_loans' => ['liability', 'loan'], 'unsecured_loans' => ['liability', 'loan'], 'capital_account' => ['equity', 'capital'],
        'reserves_&_surplus' => ['equity', 'reserve'], 'sales_accounts' => ['income', 'sales'], 'direct_incomes' => ['income', 'other_income'],
        'indirect_incomes' => ['income', 'other_income'], 'purchase_accounts' => ['expense', 'cogs'], 'indirect_expenses' => ['expense', 'operating'],
        'suspense_a/c' => [null, null, 'a suspense account: clear it in the old books first'],
    ];

    private const NUMERIC = ['payment_terms_days', 'credit_limit', 'sale_price', 'purchase_price', 'reorder_point', 'amount', 'fx_rate'];

    public function store(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        [$controller, $permission, $action, $required] = self::KINDS[$kind];
        $this->authorizeCompany($permission);
        $data = $request->validate(['csv' => ['required', 'string', 'max:900000'], 'check' => ['sometimes', 'boolean']]);
        $check = (bool) ($data['check'] ?? false);

        [$headings, $rows] = $this->parse($data['csv']);
        $columns = self::COLUMNS[$kind];
        $used = array_values(array_filter($headings, fn ($h) => isset($columns[$h])));
        $fieldsUsed = array_map(fn ($h) => $columns[$h], $used);
        if (array_diff($required, $fieldsUsed) !== []) {
            $names = array_map(fn ($f) => '"' . self::HEADINGS[$f] . '"', $required);
            throw ValidationException::withMessages(['csv' => [count($names) === 1
                ? "The first line names the columns, and one of them is {$names[0]}."
                : 'The first line names the columns: ' . implode(', ', $names) . ' at least.']]);
        }
        $contacts = in_array($kind, ['open-invoices', 'open-bills'], true) ? $this->contactsByName($kind === 'open-bills' ? 'is_vendor' : 'is_customer') : [];
        if (count($rows) === 0 || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => ['A file has 1 to ' . self::MAX_ROWS . ' rows below its headings.']]);
        }

        $errors = [];
        $added = 0;
        $skipped = [];
        $leftOut = [];   // 096: rows of another book's that belong elsewhere here
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
                    if ($field === 'payment_terms_days' && ! ctype_digit($value)) {
                        // 096: terms as other books write them: "Net 30", "30 days", "Due on receipt".
                        $value = preg_match('/(\d+)/', $value, $m) ? $m[1] : (preg_match('/receipt|immediate|cash/i', $value) ? '0' : $value);
                    }
                    if (in_array($field, self::NUMERIC, true)) {
                        $value = str_replace([',', ' '], '', $value);
                    }
                    data_set($fields, $field, $field === 'type' ? strtolower(str_replace([' ', '-'], '_', $value)) : $value);
                }
                if ($kind === 'items' && isset($fields['type'])) {
                    // 096: QuickBooks' Non-inventory and Inventory, Sage's Stock and Non-stock, Tally's stock items.
                    $fields['type'] = ['non_inventory' => 'non_inventory', 'noninventory' => 'non_inventory', 'non_stock' => 'non_inventory', 'stock' => 'inventory',
                        'stock_item' => 'inventory', 'inventory_part' => 'inventory', 'inventory' => 'inventory', 'service' => 'service', 'services' => 'service',
                        'bundle' => 'bundle', 'group' => 'bundle'][$fields['type']] ?? $fields['type'];
                }
                if ($kind === 'items' && ! isset($fields['type'])) {
                    $fields['type'] = 'service';
                }
                if ($kind === 'accounts') {
                    $fields = $this->accountRow($fields);
                    if (isset($fields['left_out'])) {
                        $leftOut[] = ['row' => $n + 2, 'name' => $fields['name'] ?? $fields['code'], 'reason' => "Left out: {$fields['left_out']}."];
                        continue;
                    }
                    if (isset($fields['code']) && Account::query()->where('code', $fields['code'])->exists()) {
                        $skipped[] = $fields['code'];   // already in the chart (the country pack's, or an earlier import)
                        continue;
                    }
                    if (isset($fields['error'])) {
                        $errors[] = ['row' => $n + 2, 'name' => $fields['name'] ?? null, 'messages' => [$fields['error']]];
                        continue;
                    }
                }
                if (in_array($kind, ['open-invoices', 'open-bills'], true)) {
                    $fields = $this->openDocumentRow($fields, $contacts, $kind === 'open-bills' ? 'supplier' : 'customer');
                    if (isset($fields['error'])) {
                        $errors[] = ['row' => $n + 2, 'name' => $fields['label'], 'messages' => [$fields['error']]];
                        continue;
                    }
                }
                $sub = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($fields, JSON_THROW_ON_ERROR));
                $sub->setUserResolver(fn () => $request->user());
                try {
                    app()->call([app($controller), $action], ['request' => $sub]);
                    $added++;
                } catch (ValidationException $e) {
                    $errors[] = ['row' => $n + 2, 'name' => $fields['name'] ?? $fields['label'] ?? null, 'messages' => array_values(array_unique(array_merge(...array_values($e->errors()))))];
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
            'left_out' => $leftOut,
            'columns' => array_values(array_unique(array_map(fn ($h) => $columns[$h], $used))),
            'ignored_columns' => array_values(array_filter($headings, fn ($h) => $h !== '' && ! isset($columns[$h]))),
        ], $keep ? 201 : 200);
    }

    /**
     * Opening balances from a trial balance saved as CSV: each line names an account by its code, with a debit and a
     * credit or one signed balance. They go through PUT opening-balances as one set (the same checks, one entry at the
     * conversion date, any difference to opening balance equity), replacing the balances entered before.
     */
    /**
     * Module 6: manual journals from a spreadsheet (saved as CSV, or an Excel file the web turns into CSV). Each entry is
     * the rows with the same date and reference, posted as a manual journal would be (balanced, on the company's own
     * accounts and never on one kept by documents; above the approval limit it waits for approval). All are posted or
     * none; a check posts nothing and lists what would be refused, by file row.
     */
    public function journals(Request $request): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $data = $request->validate(['csv' => ['required', 'string', 'max:900000'], 'check' => ['sometimes', 'boolean']]);
        $check = (bool) ($data['check'] ?? false);

        [$headings, $rows] = $this->parse($data['csv']);
        $columns = self::JOURNAL_COLUMNS;
        $fields = array_values(array_unique(array_map(fn ($h) => $columns[$h], array_values(array_filter($headings, fn ($h) => isset($columns[$h]))))));
        foreach (['date', 'code'] as $needed) {
            if (! in_array($needed, $fields, true) || array_intersect(['debit', 'credit'], $fields) === []) {
                throw ValidationException::withMessages(['csv' => ['The first line names the columns: "Date", "Account" (its code), "Debit" and "Credit"; "Reference", "Memo" and "Description" if you have them.']]);
            }
        }
        if (count($rows) === 0 || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => ['A file has 1 to ' . self::MAX_ROWS . ' rows below its headings.']]);
        }

        $errors = [];
        $entries = [];   // "date|reference" => [date, memo, lines, rows]
        $codes = [];
        foreach ($rows as $n => $cells) {
            $row = [];
            foreach ($headings as $i => $h) {
                if (isset($columns[$h]) && ! isset($row[$columns[$h]])) {
                    $row[$columns[$h]] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $at = $n + 2;
            $amount = fn (string $f) => isset($row[$f]) && $row[$f] !== '' ? str_replace([',', ' '], '', $row[$f]) : null;
            [$debit, $credit] = [$amount('debit'), $amount('credit')];
            foreach (['debit' => $debit, 'credit' => $credit] as $f => $v) {
                if ($v !== null && ! preg_match('/^\d{1,18}(\.\d{1,2})?$/', $v)) {
                    $errors[] = ['row' => $at, 'name' => $row['reference'] ?? null, 'messages' => ["The {$f} is not an amount with up to 2 decimals."]];
                    continue 2;
                }
            }
            if (bccomp($debit ?? '0', '0', 2) === 0 && bccomp($credit ?? '0', '0', 2) === 0) {
                continue;   // a line with no amount
            }
            $date = $row['date'] ?? '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
                $errors[] = ['row' => $at, 'name' => $row['reference'] ?? null, 'messages' => ['The date is not a date such as 2026-11-30.']];
                continue;
            }
            $codes[] = $row['code'] ?? '';
            $key = $date . '|' . ($row['reference'] ?? '');
            $entries[$key] ??= ['date' => $date, 'reference' => $row['reference'] ?? '', 'memo' => '', 'lines' => [], 'rows' => []];
            if (($row['memo'] ?? '') !== '' && $entries[$key]['memo'] === '') {
                $entries[$key]['memo'] = $row['memo'];
            }
            $entries[$key]['lines'][] = ['code' => $row['code'] ?? '', 'debit' => $debit, 'credit' => $credit, 'description' => ($row['description'] ?? '') !== '' ? mb_substr($row['description'], 0, 255) : null];
            $entries[$key]['rows'][] = $at;
        }
        if (count($entries) > self::MAX_JOURNALS) {
            throw ValidationException::withMessages(['csv' => ['A file has at most ' . self::MAX_JOURNALS . ' entries.']]);
        }
        // Accounts by code, through the company-scoped model (CLAUDE.md rule 4).
        $accounts = Account::query()->whereIn('code', array_values(array_unique($codes)))->pluck('id', 'code');

        $added = 0;
        $made = [];
        if ($errors === [] && $entries === []) {
            $errors[] = ['row' => 2, 'name' => null, 'messages' => ['No line in the file has an amount.']];
        }
        if ($errors === []) {
            DB::beginTransaction();   // a savepoint: a check, or one refused entry, keeps nothing
            try {
                foreach ($entries as $e) {
                    $lines = [];
                    foreach ($e['lines'] as $i => $l) {
                        if (! isset($accounts[$l['code']])) {
                            $errors[] = ['row' => $e['rows'][$i], 'name' => $e['reference'] ?: null, 'messages' => ["No account with code {$l['code']} in the chart."]];
                            continue 2;
                        }
                        $lines[] = array_filter(['account_id' => $accounts[$l['code']], 'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['description']], fn ($v) => $v !== null);
                    }
                    $memo = $e['memo'] !== '' ? $e['memo'] : ($e['reference'] !== '' ? "Imported journal {$e['reference']}" : 'Imported journal');
                    $sub = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                        json_encode(['date' => $e['date'], 'memo' => mb_substr($memo, 0, 500), 'lines' => $lines], JSON_THROW_ON_ERROR));
                    $sub->setUserResolver(fn () => $request->user());
                    try {
                        $result = app()->call([app(JournalController::class), 'store'], ['request' => $sub])->getData(true);
                        $made[] = ['reference' => $e['reference'], 'date' => $e['date'], 'status' => $result['status'] ?? null, 'number' => $result['number'] ?? null];
                        $added++;
                    } catch (ValidationException $ex) {
                        foreach ($ex->errors() as $field => $messages) {
                            $line = preg_match('/^lines\.(\d+)\./', $field, $m) ? (int) $m[1] : null;
                            $errors[] = ['row' => $line !== null ? ($e['rows'][$line] ?? $e['rows'][0]) : $e['rows'][0], 'name' => $e['reference'] ?: null, 'messages' => array_values($messages)];
                        }
                    }
                }
            } catch (\Throwable $ex) {
                DB::rollBack();
                throw $ex;
            }
            $check || $errors !== [] ? DB::rollBack() : DB::commit();
        }
        $keep = ! $check && $errors === [];

        return response()->json([
            'kind' => 'journals', 'checked' => $check, 'rows' => count($rows), 'entries' => count($entries), 'added' => $keep ? $added : 0,
            'journals' => $keep ? $made : [], 'errors' => $errors, 'skipped' => [], 'columns' => $fields,
            'ignored_columns' => array_values(array_filter($headings, fn ($h) => $h !== '' && ! isset($columns[$h]))),
        ], $keep ? 201 : 200);
    }

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
            $t = $key($fields['type']);
            if (! isset(self::TYPES[$t]) && isset(self::FOREIGN_TYPES[$t])) {
                [$type, $kind] = self::FOREIGN_TYPES[$t];
                if ($type === null) {
                    return ['left_out' => self::FOREIGN_TYPES[$t][2], 'name' => $fields['name'] ?? null, 'code' => $fields['code'] ?? null];
                }
                $fields['type'] = $type;
                if ($kind !== null && ! isset($fields['kind'])) {
                    $fields['kind'] = $kind;
                }
            } else {
                $fields['type'] = self::TYPES[$t] ?? $t;
            }
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

    /**
     * The company's customers or suppliers by name, lower case (read through row-level security). A name two of them
     * share maps to null: such a row is entered on the opening balances page instead.
     *
     * @return array<string, ?string>
     */
    private function contactsByName(string $flag): array
    {
        $map = [];
        foreach (DB::table('contacts')->where($flag, true)->get(['id', 'name']) as $c) {
            $key = mb_strtolower(trim((string) $c->name));
            $map[$key] = array_key_exists($key, $map) ? null : (string) $c->id;
        }

        return $map === [] ? ['' => null] : $map;
    }

    /**
     * An open invoice or bill row as POST opening-balances/invoices or bills takes it: the customer or supplier by
     * name, dates as Y-m-d (30/06/2026 read day first), the base currency when none is given.
     *
     * @param array<string, string> $fields
     * @param array<string, ?string> $contacts
     * @return array<string, mixed>
     */
    private function openDocumentRow(array $fields, array $contacts, string $who): array
    {
        $name = trim((string) ($fields['contact'] ?? ''));
        $fields['label'] = trim($name . ' ' . ($fields['number'] ?? $fields['reference'] ?? ''));
        $key = mb_strtolower($name);
        if ($name === '' || ! array_key_exists($key, $contacts)) {
            return [...$fields, 'error' => "No {$who} named \"{$name}\": add or import the {$who}s first."];
        }
        if ($contacts[$key] === null) {
            return [...$fields, 'error' => "Two {$who}s are named \"{$name}\": enter this one on the opening balances page."];
        }
        $fields['contact_id'] = $contacts[$key];
        foreach (['issue_date', 'due_date'] as $date) {
            if (isset($fields[$date]) && preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $fields[$date], $m)) {
                $fields[$date] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
        }
        $fields['currency'] = strtoupper($fields['currency'] ?? $this->current()->base_currency);
        unset($fields['contact']);

        return $fields;
    }

    private function current(): \App\Domain\Tenancy\Models\Company
    {
        return app(\App\Domain\Tenancy\Support\CurrentCompany::class)->get();
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
