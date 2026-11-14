<?php

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\StaleDocument;
use App\Domain\Tenancy\Http\ResolveCompany;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RunInTenantTransaction;
use App\Http\Middleware\SecurityHeaders;
use App\Support\IllegalTransition;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.tx' => RunInTenantTransaction::class,
            'company' => ResolveCompany::class,
            'idempotent' => Idempotency::class,
        ]);
        // Order matters: transaction → tenant → idempotency → route-model binding. The tenant must be set
        // before binding runs, or row-level security hides the bound model (404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RunInTenantTransaction::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCompany::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: Idempotency::class);
        // A pure API: an unauthenticated request gets 401, never a redirect to a login page that does not exist.
        $middleware->redirectGuestsTo(fn () => null);
        // Behind the load balancer, trust only its addresses (TRUSTED_PROXIES, comma separated). Without this the
        // API cannot see that a request came over HTTPS (no HSTS), and every client shares the balancer's address,
        // so the per-address sign-in lockout would lock out all users at once.
        if ($proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))) {
            $middleware->trustProxies(at: $proxies);
        }
        $middleware->throttleApi('api'); // named limiters: AppServiceProvider::boot
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API error is JSON (401, 403, 422 ...), whatever Accept header the client sent: no redirects.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        // A record that does not exist and a record of another company get the same plain answer: no model
        // class, no echoed id, no trace, so the response cannot be used to probe what exists (SECURITY.md S1).
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
        // Someone else saved the document first: the client reloads and tries again.
        $exceptions->render(fn (StaleDocument $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'type' => 'StaleDocument',
        ], 409));
        $exceptions->render(fn (IllegalTransition $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'type' => 'IllegalTransition',
        ], 422));
        // Ledger rule violations (unbalanced, locked period ...) are user errors, not server errors.
        $exceptions->render(fn (LedgerException $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'type' => class_basename($e),
        ], 422));
    })
    ->create();
