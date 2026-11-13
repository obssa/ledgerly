<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\CurrentCompany;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes create and post requests safe to repeat. The client sends an Idempotency-Key header (a UUID it
 * generates once per user action). The first request does the work and stores its response; a repeat
 * with the same key (double click, network retry) gets that stored response back and nothing runs twice.
 *
 * Runs inside the request transaction: the key row is committed together with the work, or not at all.
 * Two simultaneous requests with the same key queue on the key's primary key, so only one does the work.
 */
final class Idempotency
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || ! $this->current->has()) {
            return $next($request);
        }
        if (strlen($key) < 8 || strlen($key) > 200) {
            return response()->json(['message' => 'Idempotency-Key must be 8 to 200 characters.'], 400);
        }

        $companyId = $this->current->id();
        $hash = hash('sha256', $request->method() . "\n" . $request->path() . "\n" . $request->getContent());

        $claimed = DB::affectingStatement(
            'insert into idempotency_keys (company_id, key, user_id, method, path, request_hash) values (?, ?, ?, ?, ?, ?) on conflict do nothing',
            [$companyId, $key, $request->user()?->getKey(), $request->method(), $request->path(), $hash],
        );

        if ($claimed === 0) {
            $stored = DB::table('idempotency_keys')->where(['company_id' => $companyId, 'key' => $key])->first();

            // A key belongs to the user who first sent it: nobody else can replay it to read the stored response.
            if ($stored !== null && (string) $stored->user_id !== (string) $request->user()?->getKey()) {
                return response()->json(['message' => 'This Idempotency-Key belongs to another user.'], 422);
            }
            if ($stored === null || ! hash_equals((string) $stored->request_hash, $hash)) {
                return response()->json(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
            }

            return (new JsonResponse(json_decode((string) $stored->response, true), (int) $stored->status_code))
                ->header('Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        // Failed requests roll back (RunInTenantTransaction), which removes the key: the client may try again.
        DB::table('idempotency_keys')->where(['company_id' => $companyId, 'key' => $key])->update([
            'status_code' => $response->getStatusCode(),
            'response' => $response->getContent() ?: 'null',
        ]);

        return $response;
    }
}
