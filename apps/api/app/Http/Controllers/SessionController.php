<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Module 11: the devices someone is signed in on, and ending one of them. Only one's own sessions, never
 * another person's; not for the shared demo accounts, whose sessions are every visitor's (routes/api.php).
 */
final class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken()?->getKey();

        $sessions = $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'device', 'ip_address', 'created_at', 'last_used_at', 'expires_at']);

        return response()->json(['data' => $sessions->map(fn ($t) => [
            'id' => (string) $t->getKey(),
            'device' => $t->device ?? 'Unknown device',
            'address' => $t->ip_address,
            'signed_in_at' => $t->created_at?->toIso8601String(),
            'last_active_at' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'current' => $t->getKey() === $current,
        ])->values()]);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(ctype_digit($session) && strlen($session) <= 18, 404);
        $token = $user->tokens()->whereKey((int) $session)->first();
        abort_if($token === null, 404);

        if ($token->getKey() === $user->currentAccessToken()?->getKey()) {
            throw ValidationException::withMessages(['session' => ['This is the device you are using: sign out instead.']]);
        }

        $token->delete();
        Log::info('sessions.ended', ['user_id' => $user->getKey(), 'session' => $token->getKey()]);

        return response()->json(status: 204);
    }
}
