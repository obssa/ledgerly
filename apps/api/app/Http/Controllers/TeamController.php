<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Models\User;
use App\Notifications\InvitationToJoin;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 11, users and invitations (users.manage: owners and admins). Who works in the company and as what; inviting
 * someone by email (046: a one-time link, and an account if they have none); changing a role; deactivating someone
 * without losing what they did; reactivating them. The database decides who may change whom (008): only an owner
 * grants or removes the owner role, and a company always keeps an owner. Every change is in the audit log.
 */
final class TeamController extends Controller
{
    public const ROLES = ['owner', 'admin', 'accountant', 'bookkeeper', 'sales', 'purchasing', 'approver', 'viewer'];

    private const LINK_DAYS = 7;

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $rows = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.company_id', $this->current->id())
            ->orderByRaw("CASE m.status WHEN 'active' THEN 0 WHEN 'invited' THEN 1 ELSE 2 END")->orderBy('u.name')->limit(500)
            ->get(['m.id', 'm.role', 'm.status', 'm.invited_at', 'm.created_at', 'u.id as user_id', 'u.name', 'u.email']);

        return response()->json([
            'data' => $rows->map(fn ($r) => ['id' => $r->id, 'user' => ['id' => $r->user_id, 'name' => $r->name, 'email' => $r->email], 'role' => $r->role,
                'status' => $r->status, 'invited_at' => $r->invited_at, 'joined_at' => $r->created_at, 'me' => $r->user_id === $request->user()->getKey()]),
            'roles' => $this->current->membership()?->role === 'owner' ? self::ROLES : array_values(array_diff(self::ROLES, ['owner'])),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in($this->rolesICanGive())],
        ]);
        $email = Str::lower(trim($data['email']));

        [$member, $user, $token, $new] = DB::transaction(function () use ($data, $email, $request) {
            $userId = DB::selectOne('SELECT app_user_id_for_invite(?) AS id', [$email])->id;
            if ($userId !== null) {
                $status = DB::table('memberships')->where(['company_id' => $this->current->id(), 'user_id' => $userId])->value('status');
                if ($status !== null) {
                    throw ValidationException::withMessages(['email' => [$status === 'deactivated'
                        ? 'This person was deactivated here: reactivate them instead.' : 'This person is already in the company, or invited.']]);
                }
            }
            $new = $userId === null;
            if ($new) {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => ['Give their name: they have no Ledgerly account yet.']]);
                }
                // An account with no usable password: it opens only through the invitation link (or a password reset).
                $created = new User();
                $created->forceFill(['name' => $name, 'email' => $email, 'password' => Str::random(64)])->save();
                $userId = $created->getKey();
            }
            $member = (string) Str::uuid();
            DB::table('memberships')->insert(['id' => $member, 'company_id' => $this->current->id(), 'user_id' => $userId, 'role' => $data['role'],
                'status' => 'invited', 'invited_at' => now()]);
            $token = $this->issue($member, $new);
            $this->audit->record($this->current->id(), 'member_invited', 'membership', $member, null, ['email' => $email, 'role' => $data['role'], 'new_account' => $new],
                userId: $request->user()->getKey());

            return [$member, User::query()->findOrFail($userId), $token, $new];
        });
        $this->send($user, $token, $data['role'], $new, $request);

        return response()->json($this->member($member), 201);
    }

    /** A new link for an invitation not yet accepted (the old one stops working). */
    public function resend(Request $request, string $membership): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $m = $this->find($membership);
        if ($m->status !== 'invited') {
            throw ValidationException::withMessages(['membership' => ['Only an invitation not yet accepted is sent again.']]);
        }
        $user = User::query()->findOrFail($m->user_id);
        $token = DB::transaction(function () use ($m, $user, $request) {
            $token = $this->issue($m->id, $this->isNewAccount($user));
            $this->audit->record($this->current->id(), 'invitation_resent', 'membership', $m->id, null, ['email' => $user->email], userId: $request->user()->getKey());

            return $token;
        });
        $this->send($user, $token, $m->role, $this->isNewAccount($user), $request);

        return response()->json($this->member($m->id));
    }

    public function update(Request $request, string $membership): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $m = $this->find($membership);
        $role = $request->validate(['role' => ['required', Rule::in(self::ROLES)]])['role'];
        if ($role !== $m->role) {
            $this->change($m, ['role' => $role], 'member_role_changed', $request);
        }

        return response()->json($this->member($m->id));
    }

    /** Deactivates someone (or withdraws an invitation): they keep their history, but no longer work in the company. */
    public function deactivate(Request $request, string $membership): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $m = $this->find($membership);
        if ($m->user_id === $request->user()->getKey()) {
            throw ValidationException::withMessages(['membership' => ['You cannot deactivate yourself.']]);
        }
        if ($m->status === 'deactivated') {
            throw ValidationException::withMessages(['membership' => ['Already deactivated.']]);
        }
        $this->change($m, ['status' => 'deactivated'], $m->status === 'invited' ? 'invitation_withdrawn' : 'member_deactivated', $request);

        return response()->json($this->member($m->id));
    }

    public function reactivate(Request $request, string $membership): JsonResponse
    {
        $this->authorizeCompany('users.manage');
        $m = $this->find($membership);
        if ($m->status !== 'deactivated') {
            throw ValidationException::withMessages(['membership' => ['Only someone deactivated is reactivated.']]);
        }
        $this->change($m, ['status' => 'active'], 'member_reactivated', $request);

        return response()->json($this->member($m->id));
    }

    /** @return list<string> an admin cannot make an owner (the database refuses it too) */
    private function rolesICanGive(): array
    {
        return $this->current->membership()?->role === 'owner' ? self::ROLES : array_values(array_diff(self::ROLES, ['owner']));
    }

    private function issue(string $membership, bool $new): string
    {
        $token = Str::random(64);
        DB::select('SELECT invitation_issue(?::uuid, ?, ?, ?::timestamptz)', [$membership, hash('sha256', $token), $new, now()->addDays(self::LINK_DAYS)->toIso8601String()]);

        return $token;
    }

    /**
     * An account made by an invitation and never opened: its email is not confirmed yet. (For an older account whose
     * email was never confirmed, the link sets its password, as a password reset would: the link proves the mailbox.)
     */
    private function isNewAccount(User $user): bool
    {
        return $user->email_verified_at === null;
    }

    private function send(User $user, string $token, string $role, bool $new, Request $request): void
    {
        $company = $this->current->get();
        $user->notify(new InvitationToJoin($token, (string) ($company->trading_name ?: $company->legal_name), (string) $request->user()->name, $role, $new, self::LINK_DAYS));
    }

    /** @param array<string, string> $set */
    private function change(object $m, array $set, string $action, Request $request): void
    {
        try {
            DB::transaction(function () use ($m, $set, $action, $request) {
                DB::table('memberships')->where('id', $m->id)->update([...$set, 'updated_at' => now()]);
                $this->audit->record($this->current->id(), $action, 'membership', $m->id, array_intersect_key((array) $m, $set), $set, userId: $request->user()->getKey());
            });
        } catch (QueryException $e) {
            // The database's rules (008): only an owner changes the owner role, and a company keeps an owner.
            $code = $e->errorInfo[0] ?? '';
            if (in_array($code, ['42501', '23514', 'P0001'], true)) {
                throw ValidationException::withMessages(['membership' => [$this->reason($e)]]);
            }
            throw $e;
        }
    }

    private function reason(QueryException $e): string
    {
        $message = (string) ($e->errorInfo[2] ?? '');

        return match (true) {
            str_contains($message, 'owner role') => 'Only an owner can grant, change or remove the owner role.',
            str_contains(strtolower($message), 'owner') => 'A company always keeps at least one active owner.',
            default => 'This change is not allowed.',
        };
    }

    private function find(string $id): object
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        return DB::table('memberships')->where(['id' => $id, 'company_id' => $this->current->id()])->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function member(string $id): array
    {
        $r = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.id', $id)
            ->first(['m.id', 'm.role', 'm.status', 'm.invited_at', 'm.created_at', 'u.id as user_id', 'u.name', 'u.email']);

        return ['id' => $r->id, 'user' => ['id' => $r->user_id, 'name' => $r->name, 'email' => $r->email], 'role' => $r->role, 'status' => $r->status,
            'invited_at' => $r->invited_at, 'joined_at' => $r->created_at];
    }
}
