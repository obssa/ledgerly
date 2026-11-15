<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\DemoAccounts;
use App\Domain\Identity\EmailTokens;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * The email address and password of an account (SECURITY.md S3).
 *
 *   POST auth/email/verify        confirm the address with the link from the email (not signed in)
 *   POST auth/email/resend        send the confirmation email again
 *   POST auth/password/forgot     email a reset link; the answer is the same whether or not the account exists
 *   POST auth/password/reset      choose a new password with the link: single use, 60 minutes
 *   POST auth/password            change the password while signed in
 *   POST auth/logout-everywhere   end every session
 *
 * A new password, by reset or by change, ends every session: all tokens are deleted. Changing it while signed
 * in returns one new token, so the device it was done on stays signed in. Tokens and passwords are never logged.
 */
final class AccountController extends Controller
{
    public function __construct(private readonly EmailTokens $tokens)
    {
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        $userId = $this->tokens->take(EmailTokens::VERIFY_EMAIL, $data['token']);
        if ($userId === null) {
            throw ValidationException::withMessages(['token' => ['This link is not valid any more. Ask for a new confirmation email.']]);
        }
        DB::table('users')->where('id', $userId)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Your email address is confirmed.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Your email address is already confirmed.']);
        }

        $key = 'verify-email:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'We have sent several emails already. Check your inbox and spam folder, or try again in 15 minutes.'], 429);
        }
        RateLimiter::hit($key, 900);
        $this->sendVerification($user);

        return response()->json(['message' => "We sent a confirmation email to {$user->email}."], 202);
    }

    /** Also called after sign-up. A mail failure is logged, not shown: the email can be asked for again. */
    public function sendVerification(User $user): void
    {
        $minutes = (int) config('security.email.verify_minutes');
        $token = $this->tokens->issue($user, EmailTokens::VERIFY_EMAIL, $minutes);
        try {
            $user->notify(new VerifyEmailAddress($token, $minutes));
        } catch (\Throwable $e) {
            Log::error('mail.verify_email_failed', ['user_id' => $user->getKey(), 'error' => $e::class]);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = Str::lower($data['email']);

        // At most three emails an hour per address, whoever asks. Over the limit the answer stays the same.
        $key = 'forgot-password:' . hash('sha256', $email);
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            // After the response has gone out: the time the answer takes must not show whether the account exists.
            defer(function () use ($email) {
                $user = User::query()->where('email', $email)->first();
                if (! $user || DemoAccounts::isDemo($user)) { // a shared demo account has no inbox and no password to reset
                    return;
                }
                $minutes = (int) config('security.password.reset_minutes');
                $token = $this->tokens->issue($user, EmailTokens::RESET_PASSWORD, $minutes);
                try {
                    $user->notify(new ResetPasswordLink($token, $minutes));
                } catch (\Throwable $e) {
                    Log::error('mail.reset_password_failed', ['user_id' => $user->getKey(), 'error' => $e::class]);
                }
            });
        }

        return response()->json(['message' => 'If that email address has an account, a link to reset the password is on its way.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'max:200', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $userId = $this->tokens->take(EmailTokens::RESET_PASSWORD, $data['token']);
            $user = $userId !== null ? User::query()->find($userId) : null;
            if (! $user) {
                throw ValidationException::withMessages(['token' => ['This link is not valid any more. Ask for a new one.']]);
            }

            $user->forceFill(['password' => $data['password']]); // hashed by the model
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()]); // the link proved the mailbox is theirs
            }
            $user->save();
            $user->tokens()->delete();

            return $user;
        });

        RateLimiter::clear('login:' . hash('sha256', $user->email));
        Log::info('password.reset', ['user_id' => $user->getKey()]);

        return response()->json(['message' => 'Your password is changed. Sign in with the new one.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200', 'different:current_password', Password::defaults()],
        ]);

        $key = 'password:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['current_password' => ['Too many wrong passwords. Try again in 15 minutes.']])->status(429);
        }
        if (! Hash::check($data['current_password'], $user->password)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['current_password' => ['The current password is not right.']]);
        }
        RateLimiter::clear($key);

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();
        Log::info('password.changed', ['user_id' => $user->getKey()]);

        // Every other session is over; this one continues on a new token.
        return response()->json(['token' => $user->issueToken()]);
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->delete();
        Log::info('sessions.ended_everywhere', ['user_id' => $user->getKey()]);

        return response()->json(status: 204);
    }
}
