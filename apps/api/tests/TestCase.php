<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Identity\Totp;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A user as most tests need one: the authenticator app already set up, because owners and admins cannot
     * work in a company without it (SECURITY.md S2), and the email address confirmed, because a company cannot
     * be created without that (S3). Pass false to test the rules themselves.
     */
    protected function createUser(string $name, string $email, bool $withAuthenticator = true, bool $verified = true): User
    {
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => 'secret-password']);
        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save(); // needed to create a company (S3)
        }
        if ($withAuthenticator) {
            $user->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();
        }

        return $user->refresh(); // with every column, as a signed-in user is loaded
    }
}
