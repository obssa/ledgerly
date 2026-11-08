<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

/*
| Ledgerly public API v1. The Next.js app uses exactly this API, so every feature is API-first.
| Company-scoped routes need the X-Company-Id header (see ResolveCompany).
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('companies', [CompanyController::class, 'index']);
        Route::post('companies', [CompanyController::class, 'store']);

        Route::middleware('company')->group(function () {
            Route::get('accounts', [LedgerController::class, 'accounts']);
            Route::get('journal-entries', [LedgerController::class, 'entries']);
            Route::post('journal-entries', [LedgerController::class, 'storeEntry']);
            Route::post('journal-entries/{entry}/reverse', [LedgerController::class, 'reverse']);

            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
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
