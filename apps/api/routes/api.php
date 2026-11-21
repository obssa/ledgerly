<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\BinController;
use App\Http\Controllers\CashCountController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalRequestController;
use App\Http\Controllers\AnalysisReportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\CompanyExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ProjectMilestoneController;
use App\Http\Controllers\SavedReportController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\StockReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\FinancialStatementController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\InvoiceLinkController;
use App\Http\Controllers\QuoteLinkController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\PostDatedChequeController;
use App\Http\Controllers\LandedCostController;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\BadDebtController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\BatchInvoiceController;
use App\Http\Controllers\LateInterestController;
use App\Http\Controllers\BankRuleController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\SalesReceiptController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RecurringJournalController;
use App\Http\Controllers\ReportExportController;
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
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\LogoController;
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
use App\Http\Controllers\TagController;
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
Route::bind('salesReceipt', $documentOf(['sales_receipt']));
Route::bind('expenseClaim', $documentOf(['bill']));   // 083: the controller checks it is a claim, and whose
Route::bind('supplierCredit', $documentOf(['vendor_credit']));
Route::bind('quote', $documentOf(['quote']));
Route::bind('salesOrder', $documentOf(['sales_order']));
Route::bind('purchaseOrder', $documentOf(['purchase_order']));
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
    Route::get('auth/invitation', [InvitationController::class, 'show'])->middleware('throttle:auth');
    Route::post('auth/invitation/accept', [InvitationController::class, 'accept'])->middleware('throttle:auth');
    // The staff console (066, SECURITY.md S8): allow-listed addresses only; a staff token the database checks.
    Route::prefix('staff')->group(function () {
        Route::middleware(['staff:open', 'throttle:auth'])->group(function () {
            Route::post('login', [StaffController::class, 'signIn']);
            Route::get('setup', [StaffController::class, 'setupShow']);
            Route::post('setup', [StaffController::class, 'setupComplete']);
        });
        Route::middleware('staff')->group(function () {
            Route::post('logout', [StaffController::class, 'signOut']);
            Route::get('me', [StaffController::class, 'me']);
            Route::get('companies', [StaffController::class, 'companies']);
            Route::post('sessions', [StaffController::class, 'startSession']);
            Route::get('sessions/{session}', [StaffController::class, 'session']);
            Route::post('sessions/{session}/end', [StaffController::class, 'endSession']);
        });
    });
    // A quote seen and answered by the customer through its emailed link (065): no account, the token's hash checked.
    Route::get('public/quote', [QuoteLinkController::class, 'show'])->middleware('throttle:auth');
    Route::post('public/quote/answer', [QuoteLinkController::class, 'answer'])->middleware('throttle:auth');
    Route::get('public/invoice', [InvoiceLinkController::class, 'show'])->middleware('throttle:auth');   // 099: an invoice shared as a link
    Route::get('public/invoice/pdf', [InvoiceLinkController::class, 'pdf'])->middleware('throttle:auth');
    Route::get('auth/demo', [DemoController::class, 'index']);
    Route::post('auth/demo', [DemoController::class, 'login'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'tenant.tx'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('companies', [CompanyController::class, 'index']);

        // Not for the shared demo accounts (config/demo.php).
        Route::middleware('not.demo')->group(function () {
            Route::post('auth/logout-everywhere', [AccountController::class, 'logoutEverywhere']);
            Route::get('auth/sessions', [SessionController::class, 'index']);
            Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy']);
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
            Route::get('company/logo', [LogoController::class, 'show']);   // 100: the company's logo
            Route::get('subscription', [SubscriptionController::class, 'show']);
            Route::get('people', [PeopleController::class, 'index']);
            Route::get('members', [TeamController::class, 'index']);
            // Every visitor shares the demo accounts: none of them sends invitations or changes who works in the demo.
            Route::middleware('not.demo')->group(function () {
                Route::post('members/invitations', [TeamController::class, 'invite']);
                Route::put('members/{membership}', [TeamController::class, 'update']);
                Route::post('members/{membership}/resend', [TeamController::class, 'resend']);
                Route::post('members/{membership}/deactivate', [TeamController::class, 'deactivate']);
                Route::post('members/{membership}/reactivate', [TeamController::class, 'reactivate']);
                Route::get('company/export', [CompanyExportController::class, 'download']);
                Route::post('invoices/{invoice}/remind', [InvoiceController::class, 'remind']);
                Route::post('invoices/{invoice}/email', [InvoiceController::class, 'email']);
                Route::post('quotes/{quote}/email', [QuoteController::class, 'email']);
                Route::post('company/logo', [LogoController::class, 'store']);   // 100: not changed in the shared demo
                Route::post('company/logo/remove', [LogoController::class, 'remove']);
                Route::post('invoices/{invoice}/share', [ShareController::class, 'invoice']);   // by WhatsApp: a link and the message (099)
                Route::post('quotes/{quote}/share', [ShareController::class, 'quote']);
                // Files attached to documents (063): not stored for the shared demo accounts.
                foreach (['invoices' => ['invoice', 'invoice'], 'bills' => ['bill', 'bill'], 'expenses' => ['expense', 'expense'], 'expense-claims' => ['expenseClaim', 'expense_claim']] as $path => [$param, $kind]) {
                    Route::post("{$path}/{{$param}}/attachments", [AttachmentController::class, 'store'])->defaults('kind', $kind);
                }
                Route::post('sales-orders/{salesOrder}/email', [QuoteController::class, 'email']);
                Route::post('journal-entries/{entry}/attachments', [AttachmentController::class, 'storeJournal']);   // 086
            });
            Route::put('company', [CompanySettingsController::class, 'update']);
            Route::post('company/archive', [CompanySettingsController::class, 'archive']);   // the company kept, read-only
            Route::post('demo/start-fresh', [\App\Http\Controllers\DemoBooksController::class, 'startFresh']);   // 101: the demo's owner only
            Route::post('demo/fill', [\App\Http\Controllers\DemoBooksController::class, 'fill']);
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

            Route::get('tags', [TagController::class, 'index']);   // 094: custom tags on transactions
            Route::post('tags', [TagController::class, 'store']);
            Route::put('tags/{tag}', [TagController::class, 'update'])->whereUuid('tag');
            Route::get('reports/by-tag', [TagController::class, 'report']);
            foreach (['invoices' => 'invoice', 'bills' => 'bill', 'expenses' => 'expense'] as $path => $kind) {
                Route::get("{$path}/{{$kind}}/tags", [TagController::class, 'forDocument'])->defaults('kind', $kind);
                Route::put("{$path}/{{$kind}}/tags", [TagController::class, 'setDocument'])->defaults('kind', $kind);
            }
            Route::get('journal-entries/{entry}/tags', [TagController::class, 'forEntry']);
            Route::put('journal-entries/{entry}/tags', [TagController::class, 'setEntry']);
            Route::get('dimensions', [DimensionController::class, 'index']);
            Route::post('dimensions', [DimensionController::class, 'store']);
            Route::put('dimensions/{dimension}', [DimensionController::class, 'update']);

            Route::get('numbering', [NumberingController::class, 'index']);
            Route::put('numbering', [NumberingController::class, 'update']);

            Route::get('opening-balances', [OpeningBalanceController::class, 'show']);
            Route::put('opening-balances', [OpeningBalanceController::class, 'update']);
            Route::post('opening-balances/invoices', [OpeningBalanceController::class, 'invoice']);
            Route::post('opening-balances/bills', [OpeningBalanceController::class, 'bill']);

            Route::get('accounts', [LedgerController::class, 'accounts']);
            Route::get('chart', [ChartController::class, 'index']);
            Route::get('chart/industries', [ChartController::class, 'industries']);   // Module 1: industry templates
            Route::post('chart/industry-accounts', [ChartController::class, 'addIndustryAccounts']);
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
            Route::post('invoices/{invoice}/repeat', [RecurringInvoiceController::class, 'store']);   // 077
            Route::get('recurring-invoices', [RecurringInvoiceController::class, 'index']);
            Route::get('recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'show'])->whereUuid('recurringInvoice');
            Route::post('recurring-invoices/{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause'])->whereUuid('recurringInvoice');
            Route::post('recurring-invoices/{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume'])->whereUuid('recurringInvoice');
            Route::post('recurring-invoices/{recurringInvoice}/make-due', [RecurringInvoiceController::class, 'makeDue'])->whereUuid('recurringInvoice');
            Route::get('invoices/{invoice}/write-offs', [BadDebtController::class, 'index']);   // 078
            Route::post('invoices/{invoice}/write-offs', [BadDebtController::class, 'store']);
            Route::post('invoices/{invoice}/write-offs/reverse', [BadDebtController::class, 'reverse']);
            Route::get('invoices/{invoice}/interest', [LateInterestController::class, 'show']);   // 079
            Route::post('invoices/{invoice}/interest', [LateInterestController::class, 'store']);
            Route::post('invoices/{invoice}/batch', [BatchInvoiceController::class, 'store']);   // batch invoices
            Route::post('recurring-journals', [RecurringJournalController::class, 'store']);
            Route::post('recurring-journals/post-due', [RecurringJournalController::class, 'postDue']);
            Route::get('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'show'])->whereUuid('recurringJournal');
            Route::put('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'update'])->whereUuid('recurringJournal');
            Route::post('recurring-journals/{recurringJournal}/pause', [RecurringJournalController::class, 'pause'])->whereUuid('recurringJournal');
            Route::post('recurring-journals/{recurringJournal}/resume', [RecurringJournalController::class, 'resume'])->whereUuid('recurringJournal');

            Route::get('tax-rates', [TaxRateController::class, 'index']);
            Route::get('fixed-assets', [FixedAssetController::class, 'index']);
            Route::post('fixed-assets', [FixedAssetController::class, 'store']);
            Route::get('fixed-assets/tax-depreciation', [FixedAssetController::class, 'taxSchedule']);
            Route::get('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])->whereUuid('fixedAsset');
            Route::put('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])->whereUuid('fixedAsset');
            Route::post('fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->whereUuid('fixedAsset');
            Route::post('fixed-assets/{fixedAsset}/impair', [FixedAssetController::class, 'impair'])->whereUuid('fixedAsset');   // 091
            Route::post('fixed-assets/{fixedAsset}/revalue', [FixedAssetController::class, 'revalue'])->whereUuid('fixedAsset');   // 092
            Route::get('leases', [LeaseController::class, 'index']);   // 095: leases, as the lessee
            Route::post('leases', [LeaseController::class, 'store']);
            Route::get('leases/{lease}', [LeaseController::class, 'show'])->whereUuid('lease');
            Route::post('leases/{lease}/payments', [LeaseController::class, 'pay'])->whereUuid('lease');
            Route::post('leases/{lease}/end', [LeaseController::class, 'end'])->whereUuid('lease');
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
            Route::put('projects/{project}/grant', [ProjectController::class, 'grant'])->whereUuid('project');   // 093
            Route::get('projects/{project}/donor-report', [ProjectController::class, 'donorReport'])->whereUuid('project');
            Route::post('projects/{project}/milestones', [ProjectMilestoneController::class, 'store'])->whereUuid('project');
            Route::put('projects/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'update'])->whereUuid(['project', 'milestone']);
            Route::delete('projects/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy'])->whereUuid(['project', 'milestone']);
            Route::post('projects/{project}/milestones/{milestone}/bill', [ProjectMilestoneController::class, 'bill'])->whereUuid(['project', 'milestone']);
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
            Route::post('imports/customers', [ImportController::class, 'store'])->defaults('kind', 'customers');
            Route::post('imports/suppliers', [ImportController::class, 'store'])->defaults('kind', 'suppliers');
            Route::post('imports/items', [ImportController::class, 'store'])->defaults('kind', 'items');
            Route::post('imports/accounts', [ImportController::class, 'store'])->defaults('kind', 'accounts');
            Route::post('imports/opening-balances', [ImportController::class, 'openingBalances']);
            Route::post('imports/journals', [ImportController::class, 'journals']);   // Module 6
            Route::post('imports/open-invoices', [ImportController::class, 'store'])->defaults('kind', 'open-invoices');
            Route::post('imports/open-bills', [ImportController::class, 'store'])->defaults('kind', 'open-bills');
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
            Route::post('invoices/{invoice}/retention', [InvoiceController::class, 'retain']);
            Route::post('invoices/{invoice}/retention/release', [InvoiceController::class, 'releaseRetention']);
            Route::get('invoices/{invoice}/history', [InvoiceController::class, 'history']);
            Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);

            // Quotes and sales orders (056): the same controller, each route finding only its own kind.
            Route::get('sales-orders/back-orders', [QuoteController::class, 'backOrders']);   // 067, before {salesOrder}
            foreach (['quotes' => ['quote', 'quote'], 'sales-orders' => ['sales_order', 'salesOrder']] as $path => [$kind, $param]) {
                Route::get($path, [QuoteController::class, 'index'])->defaults('kind', $kind);
                Route::post($path, [QuoteController::class, 'store'])->defaults('kind', $kind);
                Route::get("{$path}/{{$param}}", [QuoteController::class, 'show']);
                Route::put("{$path}/{{$param}}", [QuoteController::class, 'update']);
                Route::post("{$path}/{{$param}}/accept", [QuoteController::class, 'accept']);
                Route::post("{$path}/{{$param}}/cancel", [QuoteController::class, 'cancel']);
                Route::post("{$path}/{{$param}}/convert", [QuoteController::class, 'convert']);
                Route::get("{$path}/{{$param}}/history", [QuoteController::class, 'history']);
            }
            Route::post('quotes/{quote}/send', [QuoteController::class, 'send']);
            Route::post('sales-orders/{salesOrder}/close', [QuoteController::class, 'close']);
            Route::post('sales-orders/{salesOrder}/deliver', [DeliveryNoteController::class, 'deliver']);
            Route::get('delivery-notes', [DeliveryNoteController::class, 'index']);
            Route::get('delivery-notes/uninvoiced', [DeliveryNoteController::class, 'uninvoiced']);   // before {deliveryNote}
            Route::get('delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'show'])->whereUuid('deliveryNote');
            Route::post('delivery-notes/{deliveryNote}/void', [DeliveryNoteController::class, 'void'])->whereUuid('deliveryNote');
            foreach (['invoices' => ['invoice', 'invoice'], 'bills' => ['bill', 'bill'], 'expenses' => ['expense', 'expense'], 'expense-claims' => ['expenseClaim', 'expense_claim']] as $path => [$param, $kind]) {
                Route::get("{$path}/{{$param}}/attachments", [AttachmentController::class, 'index'])->defaults('kind', $kind);
            }
            Route::get('journal-entries/{entry}/attachments', [AttachmentController::class, 'indexJournal']);   // 086
            Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])->whereUuid('attachment');
            Route::post('attachments/{attachment}/remove', [AttachmentController::class, 'remove'])->whereUuid('attachment');
            Route::post('quotes/{quote}/decline', [QuoteController::class, 'decline']);
            Route::post('quotes/{quote}/extend', [QuoteController::class, 'extend']);

            Route::post('invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store']);
            Route::get('credit-notes', [CreditNoteController::class, 'index']);
            Route::post('credit-notes/{creditNote}/apply', [CreditNoteController::class, 'apply']);

            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement']);
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);

            // Purchase orders (068): issued to a supplier, billed in parts, closed or cancelled; nothing posts.
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('purchase-orders/open', [PurchaseOrderController::class, 'open']);   // before {purchaseOrder}
            Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
            Route::post('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue']);
            Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('purchase-orders/{purchaseOrder}/bill', [PurchaseOrderController::class, 'bill']);
            Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close']);
            Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
            Route::get('goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::get('goods-receipts/unbilled', [GoodsReceiptController::class, 'unbilled']);   // before {goodsReceipt}
            Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->whereUuid('goodsReceipt');
            Route::post('goods-receipts/{goodsReceipt}/void', [GoodsReceiptController::class, 'void'])->whereUuid('goodsReceipt');
            Route::get('purchase-orders/{purchaseOrder}/history', [PurchaseOrderController::class, 'history']);
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
            Route::get('bills/{bill}/landed-costs', [LandedCostController::class, 'index']);
            Route::post('bills/{bill}/landed-costs', [LandedCostController::class, 'store']);
            Route::get('supplier-credits', [SupplierCreditController::class, 'index']);
            Route::post('supplier-credits/{supplierCredit}/apply', [SupplierCreditController::class, 'apply']);

            Route::get('sales-receipts', [SalesReceiptController::class, 'index']);
            Route::post('sales-receipts', [SalesReceiptController::class, 'store']);
            Route::get('sales-receipts/{salesReceipt}', [SalesReceiptController::class, 'show']);
            Route::post('sales-receipts/{salesReceipt}/void', [SalesReceiptController::class, 'void']);
            Route::post('sales-receipts/{salesReceipt}/credit-notes', [CreditNoteController::class, 'storeForCashSale']);   // 082

            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void']);

            Route::get('payments-made', [PaymentMadeController::class, 'index']);
            Route::post('payments-made', [PaymentMadeController::class, 'store']);
            Route::post('payments-made/batch', [PaymentMadeController::class, 'batch']);   // Module 3: batch payments
            Route::get('payments-made/bank-file', [PaymentMadeController::class, 'bankFile']);   // Module 3: the bank payment file
            Route::get('purchase-requisitions', [PurchaseRequisitionController::class, 'index']);   // 084
            Route::post('purchase-requisitions', [PurchaseRequisitionController::class, 'store']);
            Route::get('purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'show'])->whereUuid('purchaseRequisition');
            Route::post('purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->whereUuid('purchaseRequisition');
            Route::post('purchase-requisitions/{purchaseRequisition}/reject', [PurchaseRequisitionController::class, 'reject'])->whereUuid('purchaseRequisition');
            Route::post('purchase-requisitions/{purchaseRequisition}/withdraw', [PurchaseRequisitionController::class, 'withdraw'])->whereUuid('purchaseRequisition');
            Route::post('purchase-requisitions/{purchaseRequisition}/order', [PurchaseRequisitionController::class, 'order'])->whereUuid('purchaseRequisition');
            Route::get('expense-claims', [ExpenseClaimController::class, 'index']);   // 083
            Route::post('expense-claims', [ExpenseClaimController::class, 'store']);
            Route::get('expense-claims/{expenseClaim}', [ExpenseClaimController::class, 'show'])->whereUuid('expenseClaim');
            Route::post('expense-claims/{expenseClaim}/submit', [ExpenseClaimController::class, 'submit'])->whereUuid('expenseClaim');
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
            Route::post('items/{item}/units', [ItemController::class, 'addUnit']);   // 072
            Route::put('items/{item}/components', [ItemController::class, 'setComponents']);   // 073
            Route::post('items/{item}/variants', [ItemController::class, 'makeVariants']);   // 089
            Route::put('items/{item}/locations', [ItemController::class, 'setLocation']);   // 090
            Route::get('items/{item}/assembly', [AssemblyController::class, 'show']);   // 087
            Route::put('items/{item}/assembly', [AssemblyController::class, 'setParts']);
            Route::post('items/{item}/builds', [AssemblyController::class, 'build']);
            Route::get('price-lists', [PriceListController::class, 'index']);   // 080
            Route::get('price-lists/prices', [PriceListController::class, 'prices']);
            Route::post('price-lists', [PriceListController::class, 'store']);
            Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->whereUuid('priceList');
            Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->whereUuid('priceList');
            Route::put('price-lists/{priceList}/prices', [PriceListController::class, 'setPrices'])->whereUuid('priceList');
            Route::post('items/{item}/units/{unit}/archive', [ItemController::class, 'archiveUnit'])->whereUuid('unit');
            Route::get('warehouses', [WarehouseController::class, 'index']);
            Route::post('warehouses', [WarehouseController::class, 'store']);
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::get('bins', [BinController::class, 'index']);   // 098: bins inside warehouses
            Route::post('bins', [BinController::class, 'store']);
            Route::put('bins/{bin}', [BinController::class, 'update'])->whereUuid('bin');
            Route::put('bins/{bin}/items', [BinController::class, 'setItems'])->whereUuid('bin');
            Route::get('stock', [StockController::class, 'index']);
            Route::post('stock/adjustments', [StockController::class, 'adjust']);
            Route::post('stock/issues', [StockController::class, 'issue']);
            Route::post('stock/counts', [StockController::class, 'count']);
            Route::post('stock/transfers', [StockController::class, 'transfer']);
            Route::get('stock/reorder', [StockController::class, 'reorder']);
            Route::post('stock/reorder/purchase-orders', [StockController::class, 'orderLow']);   // 075
            Route::get('stock/batches', [StockController::class, 'batches']);   // 074

            Route::get('bank-accounts', [BankAccountController::class, 'index']);
            Route::post('bank-accounts', [BankAccountController::class, 'store']);
            Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show']);
            Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update']);
            Route::get('bank-accounts/{bankAccount}/register', [BankAccountController::class, 'register']);
            Route::get('bank-accounts/{bankAccount}/cheques', [ChequeController::class, 'index']);
            // Post-dated cheques (062): held, then presented as a payment, or cancelled.
            Route::get('post-dated-cheques', [PostDatedChequeController::class, 'index']);
            Route::post('post-dated-cheques', [PostDatedChequeController::class, 'store']);
            Route::post('post-dated-cheques/{cheque}/present', [PostDatedChequeController::class, 'present']);
            Route::post('post-dated-cheques/{cheque}/cancel', [PostDatedChequeController::class, 'cancel']);
            Route::get('bank-accounts/{bankAccount}/cash-counts', [CashCountController::class, 'index']);
            Route::post('bank-accounts/{bankAccount}/cash-counts', [CashCountController::class, 'store']);
            Route::get('bank-accounts/{bankAccount}/statement-lines', [StatementController::class, 'index']);
            Route::post('bank-accounts/{bankAccount}/statements', [StatementController::class, 'import']);
            Route::post('statement-lines/{statementLine}/match', [StatementController::class, 'match']);
            Route::post('statement-lines/{statementLine}/categorise', [StatementController::class, 'categorise']);
            Route::post('statement-lines/{statementLine}/split', [StatementController::class, 'split']);   // 077
            Route::post('statement-lines/{statementLine}/exclude', [StatementController::class, 'exclude']);
            Route::post('statement-lines/{statementLine}/undo', [StatementController::class, 'undo']);
            Route::post('bank-accounts/{bankAccount}/apply-rules', [StatementController::class, 'applyRules']);   // 076
            Route::get('bank-rules', [BankRuleController::class, 'index']);
            Route::post('bank-rules', [BankRuleController::class, 'store']);
            Route::put('bank-rules/{bankRule}', [BankRuleController::class, 'update'])->whereUuid('bankRule');
            Route::delete('bank-rules/{bankRule}', [BankRuleController::class, 'destroy'])->whereUuid('bankRule');
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
            Route::get('reports/stock-movement', [StockReportController::class, 'movement']);
            Route::get('reports/stock-ageing', [StockReportController::class, 'ageing']);
            Route::get('reports/ar-ageing', [LedgerController::class, 'arAgeing']);
            Route::get('reports/ap-ageing', [LedgerController::class, 'apAgeing']);
            Route::get('reports/profit-and-loss', [LedgerController::class, 'profitAndLoss']);
            Route::get('reports/cash-flow', [FinancialStatementController::class, 'cashFlow']);
            Route::get('reports/cash-forecast', [FinancialStatementController::class, 'cashForecast']);
            Route::get('reports/kpis', [FinancialStatementController::class, 'kpis']);
            Route::get('reports/changes-in-equity', [FinancialStatementController::class, 'changesInEquity']);
            Route::get('reports/notes', [FinancialStatementController::class, 'notes']);   // Module 10: the notes pack
            Route::get('reports/financial-health', [FinancialStatementController::class, 'health']);
            Route::get('reports/profit-by-dimension', [AnalysisReportController::class, 'profitByDimension']);
            Route::get('reports/custom', [AnalysisReportController::class, 'custom']);
            Route::get('reports/sales', [AnalysisReportController::class, 'sales']);
            Route::post('reports/exports', [ReportExportController::class, 'store']);
            Route::get('saved-reports', [SavedReportController::class, 'index']);
            Route::post('saved-reports', [SavedReportController::class, 'store']);
            Route::get('saved-reports/{savedReport}', [SavedReportController::class, 'show']);
            Route::put('saved-reports/{savedReport}', [SavedReportController::class, 'update']);
            Route::delete('saved-reports/{savedReport}', [SavedReportController::class, 'destroy']);
            Route::get('audit-log', [AuditLogController::class, 'index']);
            Route::get('audit-log/verify', [AuditLogController::class, 'verify']);
            Route::get('reports/purchases', [AnalysisReportController::class, 'purchases']);
        });
    });
});
