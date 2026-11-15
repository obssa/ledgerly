<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The shared demo accounts (database/schema/018_demo_accounts.sql). Only the owner role writes the list, so an
 * account is a demo account only if the deploy made it one.
 */
final class DemoAccounts
{
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    public static function isDemo(User|string|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id !== null && DB::table('demo_accounts')->where('user_id', $id)->exists();
    }

    /** The demo account for a role, when the role is offered and set up. */
    public static function forRole(string $role): ?User
    {
        if (! array_key_exists($role, (array) config('demo.roles'))) {
            return null;
        }
        $id = DB::table('demo_accounts')->where('role', $role)->value('user_id');

        return $id ? User::query()->find($id) : null;
    }

    /** @return list<string> the offered roles that are set up, in the configured order */
    public static function roles(): array
    {
        $ready = DB::table('demo_accounts')->pluck('role')->all();

        return array_values(array_filter(array_keys((array) config('demo.roles')), fn (string $r) => in_array($r, $ready, true)));
    }
}
