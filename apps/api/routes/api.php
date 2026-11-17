<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\CashCountController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalRequestController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RecurringJournalController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\RevaluationController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DimensionController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\NumberingController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PaymentMadeController;
use App\Http\Controllers\PaymentReceivedController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierCreditController;
use App\Http\Controllers\TaxRateController;
use App\Http\Controllers\TaxReturnController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\WarehouseController;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Banking\Models\Reconciliation;
use App\Domain\Ledger\Models\Account;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

/*
| Ledgerly public API v1. The Next.js app uses exactly this API, so every feature is API-first.
| Company-scoped routes need the X-Company-Id header (see ResolveCompany).
| Every authenticated request is one database transaction with the tenant set (RunInTenantTransaction).
| POST and PUT requests may send an Idempotency-Key header and are then safe to repeat (Idempotency).
*/

/*
| Invoices and bills share one table. Each route finds only its own kind, so a bill's id on an invoice route is not
| found, and the other way round. Resolved inside the request's tenant transaction, through the company-scoped model.
*/
$documentOf = fn (array $types) => fn (string $id) => Str::isUuid($id)
    ? Document::query()->whereIn('type', $types)->findOrFail($id)
    : abort(404);
Route::bind('invoice', $documentOf(['invoice', 'credit_note']));
Route::bind('bill', $documentOf(['bill', 'vendor_credit']));
Route::bind('expense', $documentOf(['expense']));
Route::bind('supplierCredit', $documentOf(['vendor_credit']));
// Payments received and payments made share one table too.
$paymentOf = fn (array $where) => fn (string $id) => Str::isUuid($id)
    ? Payment::query()->where($where)->findOrFail($id)
    : abort(404);
Route::bind('payment', $paymentOf(['direction' => 'in', 'kind' => 'payment']));
Route::bind('paymentMade', $paymentOf(['direction' => 'out', 'kind' => 'payment']));
Route::bind('refund', $paymentOf(['kind' => 'refund']));
// A money account: bank, wallet, cash, or undeposited funds; any other account is not found on these routes.
Route::bind('bankAccount', fn (string $id) => Str::isUuid($id)
    ? Account::query()->where(fn ($q) => $q->whereIn('subtype', BankAccountController::KINDS)->orWhere('system_key', 'undeposited_funds'))->findOrFail($id)
    : abort(404));
Route::bind('statementLine', fn (string $id) => Str::isUuid($id) ? BankTransaction::query()->findOrFail($id) : abort(404));
Route::bind('reconciliation', fn (string $id) => Str::isUuid($id) ? Reconciliation::query()->findOrFail($id) : abort(404));
Route::bind('account', fn (string $id) => Str::isUuid($id) ? Account::query()->findOrFail($id) : abort(404));

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('auth/login/mfa', [AuthController::class, 'loginMfa'])->middleware('throttle:auth');
    Route::post('auth/email/verify', [AccountController::class, 'verifyEmail'])->middleware('throttle:auth');
    Route::post('auth/password/forgot', [AccountController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('auth/password/reset', [AccountController::class, 'resetPassword'])->middleware('throttle:auth');
    Route::get('auth/demo', [DemoController::class, 'index']);
    Route::post('auth/demo', [DemoController::class, 'login'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'tenant.tx'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('companies', [CompanyController::class, 'index']);

        // Not for the shared demo accounts (config/demo.php).
        Route::middleware('not.demo')->group(function () {
            Route::post('auth/logout-everywhere', [AccountController::class, 'logoutEverywhere']);
            Route::post('auth/email/resend', [AccountController::class, 'resendVerification']);
            Route::post('auth/password', [AccountController::class, 'changePassword']);

            Route::post('auth/mfa/enrol', [MfaController::class, 'enrol']);
            Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
            Route::post('auth/mfa/recovery-codes', [MfaController::class, 'recoveryCodes']);
            Route::delete('auth/mfa', [MfaController::class, 'disable']);

            Route::post('companies', [CompanyController::class, 'store']);
        });
        Route::get('company-options', [CompanyController::class, 'options']);

        Route::middleware(['company', 'idempotent'])->group(function () {
            Route::get('company', [CompanySettingsController::class, 'show']);
            Route::put('company', [CompanySettingsController::class, 'update']);
            Route::put('company/period-lock', [CompanySettingsController::class, 'periodLock']);
            Route::get('close-checklist', [CompanySettingsController::class, 'closeChecklist']);
            Route::get('fiscal-years', [FiscalYearController::class, 'index']);
            Route::get('fiscal-years/{fiscalYear}/closing', [FiscalYearController::class, 'preview'])->whereUuid('fiscalYear');
            Route::post('fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->whereUuid('fiscalYear');
            Route::post('fiscal-years/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])->whereUuid('fiscalYear');
            Route::get('fiscal-years/{fiscalYear}/adjusted-trial-balance', [FiscalYearController::class, 'adjustedTrialBalance'])->whereUuid('fiscalYear');
            Route::post('fiscal-years/{fiscalYear}/adjustments', [FiscalYearController::class, 'adjust'])->whereUuid('fiscalYear');
            Route::post('fiscal-years/{fiscalYear}/finalise', [FiscalYearController::class, 'finalise'])->whereUuid('fiscalYear');

            Route::get('currencies', [CurrencyController::class, 'index']);
            Route::put('currencies', [CurrencyController::class, 'update']);
            Route::get('exchange-rates', [CurrencyController::class, 'rates']);
            Route::post('exchange-rates', [CurrencyController::class, 'storeRate']);

            Route::get('dimensions', [DimensionController::class, 'index']);
            Route::post('dimensions', [DimensionController::class, 'store']);
            Route::put('dimensions/{dimension}', [DimensionController::class, 'update']);

            Route::get('numbering', [NumberingController::class, 'index']);
            Route::put('numbering', [NumberingController::class, 'update']);

            Route::get('opening-balances', [OpeningBalanceController::class, 'show']);
            Route::put('opening-balances', [OpeningBalanceController::class, 'update']);

            Route::get('accounts', [LedgerController::class, 'accounts']);
            Route::get('chart', [ChartController::class, 'index']);
            Route::post('accounts', [ChartController::class, 'store']);
            Route::put('accounts/{account}', [ChartController::class, 'update']);
            Route::get('journal-entries', [JournalController::class, 'index']);
            Route::post('journal-entries', [JournalController::class, 'store']);
            Route::post('journal-entries/reclassify', [JournalController::class, 'reclassify']);
            Route::get('journal-entries/review', [ReviewQueueController::class, 'index']);
            Route::get('journal-entries/{entry}', [JournalController::class, 'show']);
            Route::post('journal-entries/{entry}/review', [ReviewQueueController::class, 'review']);
            Route::post('journal-entries/{entry}/reverse', [JournalController::class, 'reverse']);
            Route::get('journal-requests', [JournalRequestController::class, 'index']);
            Route::get('revaluations', [RevaluationController::class, 'index']);
            Route::get('revaluations/preview', [RevaluationController::class, 'preview']);
            Route::post('revaluations', [RevaluationController::class, 'store']);
            Route::get('journal-requests/{journalRequest}', [JournalRequestController::class, 'show'])->whereUuid('journalRequest');
            Route::post('journal-requests/{journalRequest}/approve', [JournalRequestController::class, 'approve'])->whereUuid('journalRequest');
            Route::post('journal-requests/{journalRequest}/reject', [JournalRequestController::class, 'reject'])->whereUuid('journalRequest');
            Route::post('journal-requests/{journalRequest}/withdraw', [JournalRequestController::class, 'withdraw'])->whereUuid('journalRequest');
            Route::get('recurring-journals', [RecurringJournalController::class, 'index']);
            Route::post('recurring-journals', [RecurringJournalController::class, 'store']);
            Route::post('recurring-journals/post-due', [RecurringJournalController::class, 'postDue']);
            Route::get('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'show'])->whereUuid('recurringJournal');
            Route::put('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'update'])->whereUuid('recurringJournal');
            Route::post('recurring-journals/{recurringJournal}/pause', [RecurringJournalController::class, 'pause'])->whereUuid('recurringJournal');
            Route::post('recurring-journals/{recurringJournal}/resume', [RecurringJournalController::class, 'resume'])->whereUuid('recurringJournal');

            Route::get('tax-rates', [TaxRateController::class, 'index']);
            Route::get('fixed-assets', [FixedAssetController::class, 'index']);
            Route::post('fixed-assets', [FixedAssetController::class, 'store']);
            Route::get('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])->whereUuid('fixedAsset');
            Route::put('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])->whereUuid('fixedAsset');
            Route::post('fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->whereUuid('fixedAsset');
            Route::get('depreciation/preview', [FixedAssetController::class, 'preview']);
            Route::post('depreciation-runs', [FixedAssetController::class, 'run']);
            Route::get('projects', [ProjectController::class, 'index']);
            Route::post('projects/{project}/bill-time', [TimeEntryController::class, 'bill'])->whereUuid('project');
            Route::get('time-entries', [TimeEntryController::class, 'index']);
            Route::post('time-entries', [TimeEntryController::class, 'store']);
            Route::put('time-entries/{timeEntry}', [TimeEntryController::class, 'update'])->whereUuid('timeEntry');
            Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy'])->whereUuid('timeEntry');
            Route::post('projects', [ProjectController::class, 'store']);
            Route::get('projects/{project}', [ProjectController::class, 'show'])->whereUuid('project');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->whereUuid('project');
            Route::get('tax/worksheet', [TaxReturnController::class, 'worksheet']);
            Route::get('tax/calendar', [TaxReturnController::class, 'calendar']);
            Route::get('tax/audit-file', [TaxReturnController::class, 'auditFile']);
            Route::get('tax-returns', [TaxReturnController::class, 'index']);
            Route::post('tax-returns', [TaxReturnController::class, 'store']);
            Route::get('tax-returns/{taxReturn}', [TaxReturnController::class, 'show'])->whereUuid('taxReturn');
            Route::post('tax-returns/{taxReturn}/settle', [TaxReturnController::class, 'settle'])->whereUuid('taxReturn');
            Route::post('tax-rates', [TaxRateController::class, 'store']);

            Route::get('customers', [CustomerController::class, 'index']);
            Route::post('customers', [CustomerController::class, 'store']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::get('customers/{customer}/statement', [CustomerController::class, 'statement']);
            Route::put('customers/{customer}', [CustomerController::class, 'update']);

            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit']);
            Route::post('invoices/{invoice}/reject', [InvoiceController::class, 'reject']);
            Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
            Route::get('invoices/{invoice}/history', [InvoiceController::class, 'history']);

            Route::post('invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store']);
            Route::get('credit-notes', [CreditNoteController::class, 'index']);
            Route::post('credit-notes/{creditNote}/apply', [CreditNoteController::class, 'apply']);

            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement']);
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);

            Route::get('bills', [BillController::class, 'index']);
            Route::post('bills', [BillController::class, 'store']);
            Route::get('bills/{bill}', [BillController::class, 'show']);
            Route::put('bills/{bill}', [BillController::class, 'update']);
            Route::post('bills/{bill}/submit', [BillController::class, 'submit']);
            Route::post('bills/{bill}/reject', [BillController::class, 'reject']);
            Route::post('bills/{bill}/approve', [BillController::class, 'approve']);
            Route::post('bills/{bill}/void', [BillController::class, 'void']);
            Route::get('bills/{bill}/history', [BillController::class, 'history']);

            Route::post('bills/{bill}/credits', [SupplierCreditController::class, 'store']);
            Route::get('supplier-credits', [SupplierCreditController::class, 'index']);
            Route::post('supplier-credits/{supplierCredit}/apply', [SupplierCreditController::class, 'apply']);

            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void']);

            Route::get('payments-made', [PaymentMadeController::class, 'index']);
            Route::post('payments-made', [PaymentMadeController::class, 'store']);
            Route::get('payments-made/withholding/{supplier}', [PaymentMadeController::class, 'withholding']);
            Route::get('payments-made/{paymentMade}', [PaymentMadeController::class, 'show']);
            Route::post('payments-made/{paymentMade}/reverse', [PaymentMadeController::class, 'reverse']);
            Route::post('payments-made/{paymentMade}/allocate', [PaymentMadeController::class, 'allocate']);

            Route::get('refunds', [RefundController::class, 'index']);
            Route::post('refunds/customer', [RefundController::class, 'storeCustomer']);
            Route::post('refunds/supplier', [RefundController::class, 'storeSupplier']);
            Route::get('refunds/{refund}', [RefundController::class, 'show']);
            Route::post('refunds/{refund}/reverse', [RefundController::class, 'reverse']);

            Route::get('payments-received', [PaymentReceivedController::class, 'index']);
            Route::post('payments-received', [PaymentReceivedController::class, 'store']);
            Route::get('payments-received/{payment}', [PaymentReceivedController::class, 'show']);
            Route::post('payments-received/{payment}/reverse', [PaymentReceivedController::class, 'reverse']);
            Route::post('payments-received/{payment}/bounce', [PaymentReceivedController::class, 'bounce']);
            Route::post('payments-received/{payment}/allocate', [PaymentReceivedController::class, 'allocate']);

            Route::get('items', [ItemController::class, 'index']);
            Route::post('items', [ItemController::class, 'store']);
            Route::get('items/{item}', [ItemController::class, 'show']);
            Route::put('items/{item}', [ItemController::class, 'update']);
            Route::get('warehouses', [WarehouseController::class, 'index']);
            Route::post('warehouses', [WarehouseController::class, 'store']);
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::get('stock', [StockController::class, 'index']);
            Route::post('stock/adjustments', [StockController::class, 'adjust']);
            Route::post('stock/transfers', [StockController::class, 'transfer']);
            Route::get('stock/reorder', [StockController::class, 'reorder']);

            Route::get('bank-accounts', [BankAccountController::class, 'index']);
            Route::post('bank-accounts', [BankAccountController::class, 'store']);
            Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show']);
            Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update']);
            Route::get('bank-accounts/{bankAccount}/register', [BankAccountController::class, 'register']);
            Route::get('bank-accounts/{bankAccount}/cheques', [ChequeController::class, 'index']);
            Route::get('bank-accounts/{bankAccount}/cash-counts', [CashCountController::class, 'index']);
            Route::post('bank-accounts/{bankAccount}/cash-counts', [CashCountController::class, 'store']);
            Route::get('bank-accounts/{bankAccount}/statement-lines', [StatementController::class, 'index']);
            Route::post('bank-accounts/{bankAccount}/statements', [StatementController::class, 'import']);
            Route::post('statement-lines/{statementLine}/match', [StatementController::class, 'match']);
            Route::post('statement-lines/{statementLine}/categorise', [StatementController::class, 'categorise']);
            Route::post('statement-lines/{statementLine}/exclude', [StatementController::class, 'exclude']);
            Route::post('statement-lines/{statementLine}/undo', [StatementController::class, 'undo']);
            Route::get('bank-accounts/{bankAccount}/reconciliations', [ReconciliationController::class, 'index']);
            Route::post('bank-accounts/{bankAccount}/reconciliations', [ReconciliationController::class, 'store']);
            Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show']);
            Route::put('reconciliations/{reconciliation}', [ReconciliationController::class, 'update']);
            Route::post('reconciliations/{reconciliation}/complete', [ReconciliationController::class, 'complete']);
            Route::post('reconciliations/{reconciliation}/undo', [ReconciliationController::class, 'undo']);
            Route::delete('reconciliations/{reconciliation}', [ReconciliationController::class, 'destroy']);

            Route::post('banking/transfers', [BankingController::class, 'transfer']);
            Route::post('banking/deposits', [BankingController::class, 'deposit']);

            Route::get('reports/trial-balance', [LedgerController::class, 'trialBalance']);
            Route::get('reports/general-ledger', [LedgerController::class, 'generalLedger']);
            Route::get('reports/balance-sheet', [LedgerController::class, 'balanceSheet']);
            Route::get('reports/bin-card', [LedgerController::class, 'binCard']);
            Route::get('reports/inventory-valuation', [LedgerController::class, 'inventoryValuation']);
            Route::get('reports/ar-ageing', [LedgerController::class, 'arAgeing']);
            Route::get('reports/ap-ageing', [LedgerController::class, 'apAgeing']);
            Route::get('reports/profit-and-loss', [LedgerController::class, 'profitAndLoss']);
        });
    });
});
