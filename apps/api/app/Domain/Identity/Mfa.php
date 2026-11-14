<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The authenticator-app second factor (SECURITY.md S2): setting it up, checking a code at sign-in, and the ten
 * one-time recovery codes for a lost phone.
 *
 * - The secret is stored encrypted (User cast). It is only "on" once the user has typed one correct code.
 * - A code is accepted once: the accepted step moves forward in a single UPDATE, so two requests racing with
 *   the same code cannot both pass.
 * - Recovery codes are stored as HMAC-SHA256 under the application key and are shown exactly once.
 */
final class Mfa
{
    public const RECOVERY_CODES = 10;

    /** Unambiguous characters only: no 0/o, 1/l/i. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** @return array{secret: string, otpauth_uri: string} for the QR code; nothing is asked at sign-in yet */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_last_step' => null])->save();
        $this->deleteRecoveryCodes($user);

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user->email, (string) config('security.mfa.issuer'))];
    }

    /** @return list<string>|null the recovery codes, shown once; null when the code is wrong */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->mfa_secret === null || ! $this->acceptCode($user, $code)) {
            return null;
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();

        return $this->newRecoveryCodes($user);
    }

    /** True when the code is right for this user now and has not been used before. */
    public function acceptCode(User $user, string $code): bool
    {
        if ($user->mfa_secret === null) {
            return false;
        }
        $step = Totp::verify($user->mfa_secret, $code, $user->mfa_last_step, now()->getTimestamp());
        if ($step === null) {
            return false;
        }

        // The comparison is repeated in the database: of two requests with the same code, one updates no row.
        $moved = DB::table('users')->where('id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('mfa_last_step')->orWhere('mfa_last_step', '<', $step))
            ->update(['mfa_last_step' => $step]);
        if ($moved === 1) {
            $user->mfa_last_step = $step;
            $user->syncOriginalAttribute('mfa_last_step');
        }

        return $moved === 1;
    }

    /** True when this recovery code existed and was unused; it is used up by this call. */
    public function acceptRecoveryCode(User $user, string $code): bool
    {
        return DB::table('user_recovery_codes')
            ->where('user_id', $user->getKey())
            ->where('code_hash', $this->hash($code))
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /** @return list<string> ten new codes; the old ones stop working */
    public function newRecoveryCodes(User $user): array
    {
        $this->deleteRecoveryCodes($user);

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = $this->randomCode();
        }
        DB::table('user_recovery_codes')->insert(array_map(
            fn (string $code) => ['user_id' => $user->getKey(), 'code_hash' => $this->hash($code)],
            $codes,
        ));

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return DB::table('user_recovery_codes')->where('user_id', $user->getKey())->whereNull('used_at')->count();
    }

    public function disable(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_last_step' => null])->save();
        $this->deleteRecoveryCodes($user);
    }

    private function deleteRecoveryCodes(User $user): void
    {
        DB::table('user_recovery_codes')->where('user_id', $user->getKey())->delete();
    }

    /** Ten characters in two groups, about 49 bits: xxxxx-xxxxx. */
    private function randomCode(): string
    {
        $pick = fn () => self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
        $half = fn () => implode('', array_map($pick, range(1, 5)));

        return $half() . '-' . $half();
    }

    private function hash(string $code): string
    {
        $normalised = preg_replace('/[^a-z0-9]/', '', strtolower($code)) ?? '';

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }
}
