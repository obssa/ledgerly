<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DimensionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\NumberingController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\TaxRateController;
use Illuminate\Support\Facades\Route;

/*
| Ledgerly public API v1. The Next.js app uses exactly this API, so every feature is API-first.
| Company-scoped routes need the X-Company-Id header (see ResolveCompany).
| Every authenticated request is one database transaction with the tenant set (RunInTenantTransaction).
| POST and PUT requests may send an Idempotency-Key header and are then safe to repeat (Idempotency).
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('auth/login/mfa', [AuthController::class, 'loginMfa'])->middleware('throttle:auth');
    Route::post('auth/email/verify', [AccountController::class, 'verifyEmail'])->middleware('throttle:auth');
    Route::post('auth/password/forgot', [AccountController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('auth/password/reset', [AccountController::class, 'resetPassword'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'tenant.tx'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout-everywhere', [AccountController::class, 'logoutEverywhere']);
        Route::post('auth/email/resend', [AccountController::class, 'resendVerification']);
        Route::post('auth/password', [AccountController::class, 'changePassword']);

        Route::post('auth/mfa/enrol', [MfaController::class, 'enrol']);
        Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('auth/mfa/recovery-codes', [MfaController::class, 'recoveryCodes']);
        Route::delete('auth/mfa', [MfaController::class, 'disable']);

        Route::get('companies', [CompanyController::class, 'index']);
        Route::post('companies', [CompanyController::class, 'store']);
        Route::get('company-options', [CompanyController::class, 'options']);

        Route::middleware(['company', 'idempotent'])->group(function () {
            Route::get('company', [CompanySettingsController::class, 'show']);
            Route::put('company', [CompanySettingsController::class, 'update']);
            Route::put('company/period-lock', [CompanySettingsController::class, 'periodLock']);

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
            Route::get('journal-entries', [LedgerController::class, 'entries']);
            Route::post('journal-entries', [LedgerController::class, 'storeEntry']);
            Route::post('journal-entries/{entry}/reverse', [LedgerController::class, 'reverse']);

            Route::get('tax-rates', [TaxRateController::class, 'index']);
            Route::post('tax-rates', [TaxRateController::class, 'store']);

            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit']);
            Route::post('invoices/{invoice}/reject', [InvoiceController::class, 'reject']);
            Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
            Route::get('invoices/{invoice}/history', [InvoiceController::class, 'history']);

            Route::post('banking/transfers', [BankingController::class, 'transfer']);
            Route::post('banking/deposits', [BankingController::class, 'deposit']);

            Route::get('reports/trial-balance', [LedgerController::class, 'trialBalance']);
            Route::get('reports/balance-sheet', [LedgerController::class, 'balanceSheet']);
            Route::get('reports/bin-card', [LedgerController::class, 'binCard']);
            Route::get('reports/profit-and-loss', [LedgerController::class, 'profitAndLoss']);
        });
    });
});
