<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Mfa;
use App\Models\User;
use App\Support\ClientAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Token auth for the Next.js app and mobile app (Laravel Sanctum).
 *
 * Built here: long passwords checked against known leaked passwords, lockout after repeated failures
 * (per email and per address), the same answer and the same timing whether or not the email exists,
 * tokens that expire (config/sanctum.php).
 *
 * Authenticator-app code at sign-in: loginMfa here and MfaController (SECURITY.md S2).
 *
 * Email confirmation, password reset and change, "sign out everywhere": AccountController (SECURITY.md S3).
 */
final class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    /** A valid hash of a random, discarded password: checked when the email is unknown, so timing is the same. */
    private const NO_SUCH_USER_HASH = '$2y$12$67EfWL8jROy.ZST/qih4buEn/RvnJZxqqUkeDSYh.Wr6R4E3k77qy';

    public function register(Request $request, AccountController $account): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'max:200', Password::defaults()],
            'locale' => ['nullable', 'in:en'],
        ]);
        $data['email'] = Str::lower($data['email']);

        $user = User::query()->create($data)->refresh(); // the model hashes the password (cast 'hashed')
        $account->sendVerification($user); // a company can be created only once the address is confirmed (S3)

        return response()->json(['user' => $user, 'token' => $this->issueToken($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = Str::lower($data['email']);
        $byEmail = 'login:' . hash('sha256', $email);
        $byAddress = 'login-ip:' . ClientAddress::of($request);

        if (RateLimiter::tooManyAttempts($byEmail, self::MAX_ATTEMPTS) || RateLimiter::tooManyAttempts($byAddress, self::MAX_ATTEMPTS * 4)) {
            Log::warning('auth.login_locked', ['email_hash' => hash('sha256', $email), 'address' => ClientAddress::of($request)]);
            throw ValidationException::withMessages([
                'email' => ['Too many sign-in attempts. Try again in 15 minutes.'],
            ])->status(429);
        }

        $user = User::query()->where('email', $email)->first();
        // Always run one hash check, so the response time does not reveal whether the email exists.
        $ok = Hash::check($data['password'], $user?->password ?? self::NO_SUCH_USER_HASH);

        if (! $user || ! $ok) {
            RateLimiter::hit($byEmail, self::LOCK_SECONDS);
            RateLimiter::hit($byAddress, self::LOCK_SECONDS);
            // Who tried is kept as a hash of the address typed, never the password (SECURITY.md S10).
            Log::warning('auth.login_failed', ['email_hash' => hash('sha256', $email), 'address' => ClientAddress::of($request), 'known' => $user !== null]);
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        RateLimiter::clear($byEmail);

        // With an authenticator set up, the password alone earns no token: only a short-lived challenge
        // that the code (or a recovery code) must answer (SECURITY.md S2).
        if ($user->mfa_enabled) {
            $challenge = Str::random(64);
            Cache::put(self::challengeKey($challenge), $user->getKey(), now()->addMinutes((int) config('security.mfa.challenge_minutes')));

            Log::info('auth.login_password_ok', ['user_id' => $user->getKey(), 'address' => ClientAddress::of($request)]);

            return response()->json(['mfa_required' => true, 'challenge' => $challenge]);
        }

        Log::info('auth.login', ['user_id' => $user->getKey(), 'address' => ClientAddress::of($request)]);

        return response()->json(['user' => $user, 'token' => $this->issueToken($user)]);
    }

    /** Second step of sign-in: the authenticator code, or one of the recovery codes. */
    public function loginMfa(Request $request, Mfa $mfa): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:12'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:30'],
        ]);

        // One request at a time per challenge: a code and a recovery code sent together cannot both sign in.
        $turn = Cache::lock('mfa-turn:' . self::challengeKey($data['challenge']), 10);
        if (! $turn->get()) {
            throw ValidationException::withMessages(['code' => ['This sign-in is being checked already. Try again.']])->status(429);
        }
        try {
            return $this->finishMfa($request, $mfa, $data);
        } finally {
            $turn->release();
        }
    }

    /** @param array{challenge: string, code?: ?string, recovery_code?: ?string} $data */
    private function finishMfa(Request $request, Mfa $mfa, array $data): JsonResponse
    {
        $userId = Cache::get(self::challengeKey($data['challenge']));
        $user = $userId ? User::query()->find($userId) : null;
        if (! $user || ! $user->mfa_enabled) {
            throw ValidationException::withMessages(['challenge' => ['This sign-in has expired. Enter your email and password again.']]);
        }

        $attempts = 'mfa:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($attempts, (int) config('security.mfa.max_attempts'))) {
            throw ValidationException::withMessages(['code' => ['Too many wrong codes. Try again in 15 minutes.']])->status(429);
        }

        $ok = isset($data['code'])
            ? $mfa->acceptCode($user, $data['code'])
            : $mfa->acceptRecoveryCode($user, (string) $data['recovery_code']);
        if (! $ok) {
            RateLimiter::hit($attempts, (int) config('security.mfa.lock_seconds'));
            Log::warning('auth.mfa_failed', ['user_id' => $user->getKey(), 'address' => ClientAddress::of($request)]);
            throw ValidationException::withMessages(['code' => ['That code is not right, or it was already used.']]);
        }

        Cache::forget(self::challengeKey($data['challenge'])); // one sign-in per challenge
        RateLimiter::clear($attempts);
        Log::info('auth.login', ['user_id' => $user->getKey(), 'address' => ClientAddress::of($request), 'with' => isset($data['code']) ? 'authenticator' : 'recovery_code']);

        return response()->json(['user' => $user, 'token' => $this->issueToken($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    /** Stored under a hash, so reading the cache does not give usable challenges. */
    private static function challengeKey(string $challenge): string
    {
        return 'mfa-challenge:' . hash('sha256', $challenge);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(status: 204);
    }

    private function issueToken(User $user): string
    {
        return $user->issueToken();
    }
}
