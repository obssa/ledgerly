<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Mfa;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Setting up and managing the authenticator app for the signed-in user (SECURITY.md S2).
 *
 *   POST   auth/mfa/enrol            a new secret and its QR address; nothing is asked at sign-in yet
 *   POST   auth/mfa/confirm          the first correct code turns it on and returns the ten recovery codes, once
 *   POST   auth/mfa/recovery-codes   ten new codes, replacing the old ones (password and code needed)
 *   DELETE auth/mfa                  turn it off (password and code needed)
 *
 * Changing or removing a working authenticator needs the password again and a current code: a stolen session
 * token alone cannot take the second factor away. Codes and secrets are never logged.
 */
final class MfaController extends Controller
{
    public function __construct(private readonly Mfa $mfa)
    {
    }

    public function enrol(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->mfa_enabled) {
            return response()->json(['message' => 'An authenticator app is already set up. Turn it off first to set up a new one.'], 409);
        }

        return response()->json($this->mfa->begin($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        if ($user->mfa_enabled) {
            return response()->json(['message' => 'An authenticator app is already set up.'], 409);
        }

        $this->guardAttempts($user);
        $codes = $this->mfa->confirm($user, $data['code']);
        if ($codes === null) {
            $this->wrongCode($user);
        }
        RateLimiter::clear($this->attemptsKey($user));
        Log::info('mfa.enabled', ['user_id' => $user->getKey()]);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->reauthenticate($request);

        return response()->json(['recovery_codes' => $this->mfa->newRecoveryCodes($user)]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->reauthenticate($request);
        $this->mfa->disable($user);
        Log::info('mfa.disabled', ['user_id' => $user->getKey()]);

        return response()->json(status: 204);
    }

    /** The password and a current code, for changes to a working authenticator. */
    private function reauthenticate(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        if (! $user->mfa_enabled) {
            throw ValidationException::withMessages(['code' => ['No authenticator app is set up.']]);
        }

        $this->guardAttempts($user);
        if (! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($this->attemptsKey($user), (int) config('security.mfa.lock_seconds'));
            throw ValidationException::withMessages(['password' => ['The password is not right.']]);
        }
        if (! $this->mfa->acceptCode($user, $data['code'])) {
            $this->wrongCode($user);
        }
        RateLimiter::clear($this->attemptsKey($user));

        return $user;
    }

    private function guardAttempts(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->attemptsKey($user), (int) config('security.mfa.max_attempts'))) {
            throw ValidationException::withMessages(['code' => ['Too many wrong codes. Try again in 15 minutes.']])->status(429);
        }
    }

    private function wrongCode(User $user): never
    {
        RateLimiter::hit($this->attemptsKey($user), (int) config('security.mfa.lock_seconds'));

        throw ValidationException::withMessages(['code' => ['That code is not right, or it was already used.']]);
    }

    /** Shared with sign-in (AuthController::loginMfa): one budget of wrong codes per user. */
    private function attemptsKey(User $user): string
    {
        return 'mfa:' . $user->getKey();
    }
}
