<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time links sent by email (SECURITY.md S3): confirm an email address, reset a password.
 *
 * The token in the link is random and long; only its SHA-256 hash is stored. A new link of the same kind
 * replaces the earlier ones. A link is taken with one UPDATE, so it works exactly once, even for two requests
 * arriving together, and never after it expired.
 */
final class EmailTokens
{
    public const VERIFY_EMAIL = 'verify_email';

    public const RESET_PASSWORD = 'reset_password';

    /** @return string the token to put in the link; it cannot be read back later */
    public function issue(User $user, string $purpose, int $minutes): string
    {
        DB::table('user_email_tokens')->where('user_id', $user->getKey())->where('purpose', $purpose)->delete();

        $token = Str::random(64);
        DB::table('user_email_tokens')->insert([
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $token;
    }

    /** Uses the link up. @return string|null the user it belonged to; null when unknown, used or expired */
    public function take(string $purpose, string $token): ?string
    {
        $hash = hash('sha256', $token);
        $taken = DB::table('user_email_tokens')
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);
        if ($taken !== 1) {
            return null;
        }

        return (string) DB::table('user_email_tokens')->where('token_hash', $hash)->value('user_id');
    }

    public function forget(User $user, string $purpose): void
    {
        DB::table('user_email_tokens')->where('user_id', $user->getKey())->where('purpose', $purpose)->delete();
    }
}
