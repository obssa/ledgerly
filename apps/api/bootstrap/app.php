<?php

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Tenancy\Http\ResolveCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['company' => ResolveCompany::class]);
        // The tenant must be set before route-model binding runs, or RLS hides the bound model (404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCompany::class);
        $middleware->throttleApi('120,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ledger rule violations (unbalanced, locked period ...) are user errors, not server errors.
        $exceptions->render(fn (LedgerException $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'type' => class_basename($e),
        ], 422));
    })
    ->create();
