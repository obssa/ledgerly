<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Totp;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Support\ClientAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The staff console (SECURITY.md S8, schema 066), for Ledgerly's own support staff only, from allow-listed addresses
 * (StaffOnly). Signing in always takes the password and an authenticator code. Looking into a customer's books takes a
 * support session for that one company, with a reason, for up to two hours, and inside it everything is read in a
 * read-only transaction under row-level security for that company: staff can never post, change or delete anything in
 * a customer's books. Every step goes in the staff log, failures included.
 */
final class StaffController extends Controller
{
    private const WRONG = 'Wrong email, password or code.';

    public function signIn(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $ip = ClientAddress::of($request);

        $signedIn = DB::transaction(function () use ($data, $ip) {
            $row = DB::selectOne('SELECT staff_id, password, mfa_secret, mfa_last_step FROM staff_sign_in_find(?)', [$data['email']]);
            $step = null;
            if ($row !== null && Hash::check($data['password'], (string) $row->password)) {
                $step = Totp::verify(Crypt::decryptString((string) $row->mfa_secret), $data['code'], $row->mfa_last_step === null ? null : (int) $row->mfa_last_step, now()->getTimestamp());
            }
            if ($row === null || $step === null) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $expires = DB::selectOne('SELECT staff_sign_in_complete(?, ?, ?, ?, ?) AS ends', [$row->staff_id, $step, hash('sha256', $token), (int) config('staff.token_hours'), $ip])->ends;
            $this->log($row->staff_id, null, null, 'signed_in', [], $ip);

            return ['token' => $token, 'expires_at' => $expires];
        });
        if ($signedIn === null) {
            // Kept although the sign-in is refused: written in a transaction of its own (who tried, by a hash of the address typed).
            DB::transaction(fn () => $this->log(null, null, null, 'sign_in_failed', ['email' => hash('sha256', mb_strtolower(trim($data['email'])))], $ip));
            throw ValidationException::withMessages(['email' => [self::WRONG]]);
        }

        return response()->json($signedIn);
    }

    public function signOut(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            DB::select('SELECT staff_sign_out(?)', [$request->attributes->get('staff_token_hash')]);
            $this->log($request->attributes->get('staff')->staff_id, null, null, 'signed_out', [], ClientAddress::of($request));
        });

        return response()->json(['signed_out' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('staff');

        return response()->json(['name' => $staff->name, 'email' => $staff->email]);
    }

    /** The setup link from the operator's email: who it is for, and a new authenticator secret to scan. */
    public function setupShow(Request $request): JsonResponse
    {
        $hash = hash('sha256', $request->validate(['token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/']])['token']);
        $row = DB::transaction(fn () => DB::selectOne('SELECT staff_id, email, name FROM staff_setup_find(?)', [$hash]));
        if ($row === null) {
            return response()->json(['message' => 'This setup link is not valid any more: ask the operator for a new one.'], 404);
        }
        $secret = Totp::generateSecret();

        return response()->json(['email' => $row->email, 'name' => $row->name, 'secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $row->email, 'Ledgerly staff')]);
    }

    /** A password and the authenticator, proved with its first code: the account can then sign in. */
    public function setupComplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'password' => ['required', 'string', 'max:200', Password::min(14)->mixedCase()->numbers()],
            'secret' => ['required', 'string', 'regex:/^[A-Z2-7]{32}$/'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $step = Totp::verify($data['secret'], $data['code'], null, now()->getTimestamp());   // the app's clock, as for customers (Mfa)
        if ($step === null) {
            throw ValidationException::withMessages(['code' => ['That code does not match: scan the code again and type the six digits it shows.']]);
        }
        $ip = ClientAddress::of($request);

        return DB::transaction(function () use ($data, $step, $ip) {
            $id = DB::selectOne('SELECT staff_setup_complete(?, ?, ?, ?) AS id', [hash('sha256', $data['token']), Hash::make($data['password']),
                Crypt::encryptString($data['secret']), $step])->id;
            if ($id === null) {
                throw ValidationException::withMessages(['token' => ['This setup link is not valid any more: ask the operator for a new one.']]);
            }
            $this->log($id, null, null, 'account_set_up', [], $ip);

            return response()->json(['set_up' => true]);
        });
    }

    /** Who the customers are (names and ids only), to choose one for a support session. */
    public function companies(Request $request): JsonResponse
    {
        $search = (string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '');
        $staff = $request->attributes->get('staff');

        return DB::transaction(function () use ($request, $search, $staff) {
            $rows = DB::select('SELECT id, legal_name, trading_name, country_code, status, created_at FROM staff_companies(?, ?)',
                [$request->attributes->get('staff_token_hash'), $search]);
            $this->log($staff->staff_id, null, null, 'companies_listed', ['search' => $search], ClientAddress::of($request));

            return response()->json(['data' => $rows]);
        });
    }

    /** A support session for one company: the reason is kept, and it ends by itself. */
    public function startSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
            'minutes' => ['nullable', 'integer', 'between:5,120'],
        ], ['reason.min' => 'Say why you need to look: at least ten characters, kept in the staff log.']);
        $staff = $request->attributes->get('staff');

        return DB::transaction(function () use ($request, $data, $staff) {
            try {
                $id = DB::selectOne('SELECT staff_session_start(?, ?, ?, ?) AS id', [$request->attributes->get('staff_token_hash'), $data['company_id'], $data['reason'],
                    (int) ($data['minutes'] ?? config('staff.session_minutes'))])->id;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23514') {
                    throw ValidationException::withMessages(['company_id' => ['No such company.']]);
                }
                throw $e;
            }
            $this->log($staff->staff_id, $id, $data['company_id'], 'support_session_started', ['reason' => $data['reason']], ClientAddress::of($request));

            return response()->json(['id' => $id], 201);
        });
    }

    /**
     * What a support session sees: the company, how much is in its books, its trial balance and its latest audit records.
     * Read in a read-only transaction, inside that company only: nothing can be written.
     */
    public function session(Request $request, string $session): JsonResponse
    {
        $staff = $request->attributes->get('staff');
        $open = Str::isUuid($session)
            ? DB::transaction(fn () => DB::selectOne('SELECT company_id, expires_at, reason FROM staff_session_check(?, ?)', [$request->attributes->get('staff_token_hash'), $session]))
            : null;
        if ($open === null) {
            return response()->json(['message' => 'This support session is over: start a new one, with a reason.'], 404);
        }

        $view = DB::transaction(function () use ($open) {
            DB::statement('SET TRANSACTION READ ONLY');
            DatabaseTenantContext::set(null, $open->company_id);
            $id = $open->company_id;

            return [
                'company' => DB::table('companies')->where('id', $id)->first(['id', 'legal_name', 'trading_name', 'country_code', 'base_currency', 'status',
                    'conversion_date', 'books_closed_until', 'created_at']),
                'documents' => DB::table('documents')->where('company_id', $id)->groupBy('type', 'status')->orderBy('type')->orderBy('status')
                    ->get(['type', 'status', DB::raw('count(*) AS n')]),
                'journal_entries' => DB::table('journal_entries')->where('company_id', $id)->selectRaw('count(*) AS n, max(entry_date) AS last')->first(),
                'trial_balance' => DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                    ->where('l.company_id', $id)->groupBy('a.code', 'a.name', 'a.type')->orderBy('a.code')
                    ->get(['a.code', 'a.name', 'a.type', DB::raw('sum(l.debit)::numeric(20,2)::text AS debit'), DB::raw('sum(l.credit)::numeric(20,2)::text AS credit')]),
                'audit' => DB::table('audit_log')->where('company_id', $id)->orderByDesc('id')->limit(50)
                    ->get(['action', 'subject_type', 'actor_type', 'reason', 'created_at']),
            ];
        });
        DB::transaction(fn () => $this->log($staff->staff_id, $session, $open->company_id, 'company_viewed', [], ClientAddress::of($request)));

        return response()->json([...$view, 'session' => ['id' => $session, 'expires_at' => $open->expires_at, 'reason' => $open->reason]]);
    }

    public function endSession(Request $request, string $session): JsonResponse
    {
        if (! Str::isUuid($session)) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $staff = $request->attributes->get('staff');
        DB::transaction(function () use ($request, $session, $staff) {
            DB::select('SELECT staff_session_end(?, ?)', [$request->attributes->get('staff_token_hash'), $session]);
            $this->log($staff->staff_id, $session, null, 'support_session_ended', [], ClientAddress::of($request));
        });

        return response()->json(['ended' => true]);
    }

    /** @param array<string, mixed> $detail */
    private function log(?string $staffId, ?string $sessionId, ?string $companyId, string $action, array $detail, string $ip): void
    {
        DB::table('staff_log')->insert(['staff_id' => $staffId, 'session_id' => $sessionId, 'company_id' => $companyId, 'action' => $action,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'ip' => mb_substr($ip, 0, 64)]);
    }
}
