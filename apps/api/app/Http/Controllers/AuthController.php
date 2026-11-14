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
 * NOT built yet and required before any real customer signs in (SECURITY.md, "Before launch"):
 * email verification, password reset, "sign out everywhere".
 */
final class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    /** A valid hash of a random, discarded password: checked when the email is unknown, so timing is the same. */
    private const NO_SUCH_USER_HASH = '$2y$12$67EfWL8jROy.ZST/qih4buEn/RvnJZxqqUkeDSYh.Wr6R4E3k77qy';

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'max:200', Password::min(10)->uncompromised()],
            'locale' => ['nullable', 'in:en'],
        ]);
        $data['email'] = Str::lower($data['email']);

        $user = User::query()->create($data); // the model hashes the password (cast 'hashed')

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
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        RateLimiter::clear($byEmail);

        // With an authenticator set up, the password alone earns no token: only a short-lived challenge
        // that the code (or a recovery code) must answer (SECURITY.md S2).
        if ($user->mfa_enabled) {
            $challenge = Str::random(64);
            Cache::put(self::challengeKey($challenge), $user->getKey(), now()->addMinutes((int) config('security.mfa.challenge_minutes')));

            return response()->json(['mfa_required' => true, 'challenge' => $challenge]);
        }

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
            throw ValidationException::withMessages(['code' => ['That code is not right, or it was already used.']]);
        }

        Cache::forget(self::challengeKey($data['challenge'])); // one sign-in per challenge
        RateLimiter::clear($attempts);

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
        return $user->createToken('web', ['*'], now()->addMinutes((int) config('sanctum.expiration', 720)))->plainTextToken;
    }
}
