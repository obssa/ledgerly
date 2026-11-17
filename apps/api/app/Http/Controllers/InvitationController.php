<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Module 11: the invitation link (046), opened by someone not signed in. Shows whose invitation it is; accepting uses
 * the link up and, as the invited person, makes their membership active (and, for an account the invitation made,
 * sets its name and password and confirms its email: the link proves the mailbox).
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
        $row = DB::transaction(fn () => DB::selectOne('SELECT company_name, role, email, name, new_account, expires_at FROM invitation_lookup(?)', [hash('sha256', $token)]));

        return $row ? response()->json((array) $row) : response()->json(['message' => 'This invitation link is not valid any more. Ask for a new one.'], 404);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:200', Password::defaults()],
        ]);
        $hash = hash('sha256', $data['token']);

        $accepted = DB::transaction(function () use ($data, $hash) {
            $lookup = DB::selectOne('SELECT company_name, new_account FROM invitation_lookup(?)', [$hash]);
            if ($lookup === null) {
                throw ValidationException::withMessages(['token' => ['This invitation link is not valid any more. Ask for a new one.']]);
            }
            if ($lookup->new_account && ($data['password'] ?? null) === null) {
                throw ValidationException::withMessages(['password' => ['Choose a password for your new account.']]);
            }
            $taken = DB::selectOne('SELECT membership_id, company_id, user_id, new_account FROM invitation_take(?)', [$hash])
                ?? throw ValidationException::withMessages(['token' => ['This invitation link is not valid any more. Ask for a new one.']]);

            // From here on, as the invited person in that company: the database lets them accept only their own invitation.
            DatabaseTenantContext::set($taken->user_id, $taken->company_id);
            DB::table('memberships')->where('id', $taken->membership_id)->update(['status' => 'active', 'updated_at' => now()]);
            $user = User::query()->findOrFail($taken->user_id);
            if ($taken->new_account) {
                $name = trim((string) ($data['name'] ?? ''));
                $user->forceFill(['password' => $data['password'], 'email_verified_at' => $user->email_verified_at ?? now()] + ($name !== '' ? ['name' => $name] : []))->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $this->audit->record($taken->company_id, 'invitation_accepted', 'membership', $taken->membership_id, ['status' => 'invited'], ['status' => 'active'], userId: $taken->user_id);

            return ['company' => $lookup->company_name, 'email' => $user->email, 'user_id' => $user->getKey()];
        });
        Log::info('invitation.accepted', ['user_id' => $accepted['user_id']]);

        return response()->json(['accepted' => true, 'company' => $accepted['company'], 'email' => $accepted['email']]);
    }
}
