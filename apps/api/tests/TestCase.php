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
     * work in a company without it (SECURITY.md S2). Pass false to test the rule itself.
     */
    protected function createUser(string $name, string $email, bool $withAuthenticator = true): User
    {
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => 'secret-password']);
        if ($withAuthenticator) {
            $user->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();
        }

        return $user->refresh(); // with every column, as a signed-in user is loaded
    }
}
