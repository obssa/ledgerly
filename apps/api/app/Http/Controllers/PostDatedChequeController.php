<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Currency;
use App\Domain\Purchases\Actions\PayBills;
use App\Domain\Sales\Actions\ReceivePayment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5: post-dated cheques (062). A cheque dated later than the day it changes hands is held in a register, not in
 * the books. When its date comes it is presented and becomes a cheque payment, received (payments.receive) or made
 * (payments.pay), through the usual payment rules; until then it can be cancelled. Rows are read through row-level
 * security and every query names the company.
 */
final class PostDatedChequeController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $q = $request->validate(['status' => ['nullable', Rule::in(['held', 'presented', 'cancelled'])]]);
        $today = $this->current->today();
        $rows = DB::table('post_dated_cheques as c')->join('contacts as k', 'k.id', '=', 'c.contact_id')->join('accounts as a', 'a.id', '=', 'c.account_id')
            ->where('c.company_id', $this->current->id())
            ->when($q['status'] ?? null, fn ($w, $s) => $w->where('c.status', $s))
            ->orderByRaw("CASE WHEN c.status = 'held' THEN 0 ELSE 1 END")->orderBy('c.cheque_date')->orderBy('c.id')->limit(500)
            ->get(['c.id', 'c.direction', 'c.contact_id', 'k.name as contact_name', 'c.account_id', 'a.code as account_code', 'a.name as account_name',
                'c.cheque_number', 'c.drawn_on', 'c.cheque_date', 'c.handed_on', 'c.currency', 'c.amount', 'c.note', 'c.status', 'c.payment_id', 'c.closed_on']);
        $membership = $this->current->membership();

        return response()->json([
            'data' => $rows->map(fn ($c) => [
                'id' => $c->id, 'direction' => $c->direction, 'contact' => ['id' => $c->contact_id, 'name' => $c->contact_name],
                'account' => ['id' => $c->account_id, 'code' => $c->account_code, 'name' => $c->account_name],
                'cheque_number' => $c->cheque_number, 'drawn_on' => $c->drawn_on, 'cheque_date' => $c->cheque_date, 'handed_on' => $c->handed_on,
                'currency' => $c->currency, 'amount' => (string) $c->amount, 'note' => $c->note, 'status' => $c->status, 'payment_id' => $c->payment_id,
                'closed_on' => $c->closed_on, 'due' => $c->status === 'held' && $c->cheque_date <= $today,
            ])->values(),
            // What each side has on hand: held cheques by currency.
            'held' => DB::table('post_dated_cheques')->where(['company_id' => $this->current->id(), 'status' => 'held'])
                ->groupBy('direction', 'currency')->orderBy('direction')->orderBy('currency')
                ->get(['direction', 'currency', DB::raw('count(*) AS cheques'), DB::raw('sum(amount)::numeric(20,2)::text AS amount')]),
            'can' => ['receive' => (bool) $membership?->can('payments.receive'), 'pay' => (bool) $membership?->can('payments.pay')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // A cheque received needs payments.receive, one written payments.pay: checked before anything is read.
        $this->authorizeCompany($request->input('direction') === 'out' ? 'payments.pay' : 'payments.receive');
        $direction = $request->validate(['direction' => ['required', Rule::in(['in', 'out'])]])['direction'];
        $data = $request->validate([
            // The company's own customer or supplier and bank account: the exists rules read through row-level security.
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where($direction === 'in' ? 'is_customer' : 'is_vendor', true)],
            'account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('subtype', 'bank')->where('is_active', true)],
            'cheque_number' => ['required', ...array_filter(ChequeNumbers::RULE, fn ($r) => $r !== 'nullable')],
            'drawn_on' => ['nullable', 'string', 'max:80'],
            'handed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'cheque_date' => ['required', 'date_format:Y-m-d', 'after:handed_on'],
            'currency' => ['required', Rule::in(Currency::all())],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['cheque_date.after' => 'A post-dated cheque is dated after the day it changes hands; one dated today or earlier is recorded as a payment.']);
        $id = (string) Str::uuid7();
        try {
            DB::table('post_dated_cheques')->insert([...$data, 'id' => $id, 'company_id' => $this->current->id(), 'direction' => $direction, 'created_by' => $request->user()->getKey()]);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23505', '23514'], true)) {
                throw ValidationException::withMessages([$e->getCode() === '23505' ? 'cheque_number' : 'account_id' => [$e->getCode() === '23505'
                    ? "Cheque {$data['cheque_number']} of this account is already written." : 'The cheque goes through a bank account in its own currency.']]);
            }
            throw $e;
        }
        $this->audit->record($this->current->id(), 'post_dated_cheque_recorded', 'post_dated_cheque', $id, null, [...$data, 'direction' => $direction],
            userId: $request->user()->getKey());

        return response()->json($this->one($id), 201);
    }

    /** Its date has come: the cheque becomes a payment on the day it is banked or paid out (today, or the date given). */
    public function present(Request $request, string $cheque, ReceivePayment $receive, PayBills $pay): JsonResponse
    {
        $this->authorizeCompany($this->permissionFor($cheque));
        $c = $this->held($cheque);
        $date = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . $c->cheque_date, 'before_or_equal:' . $this->current->today()],
        ], ['date.after_or_equal' => "The cheque is dated {$c->cheque_date}: it is presented on or after that day."])['date'] ?? $this->current->today();
        if ($date < $c->cheque_date) {
            throw ValidationException::withMessages(['date' => ["The cheque is dated {$c->cheque_date}: it is presented on or after that day."]]);
        }

        $payment = DB::transaction(function () use ($c, $date, $request, $receive, $pay) {
            $row = DB::table('post_dated_cheques')->where(['company_id' => $c->company_id, 'id' => $c->id])->lockForUpdate()->first();
            if ($row->status !== 'held') {
                throw ValidationException::withMessages(['cheque' => ["Cheque {$c->cheque_number} was {$row->status} already."]]);
            }
            $body = ['contact_id' => $c->contact_id, 'account_id' => $c->account_id, 'payment_date' => $date, 'method' => 'cheque',
                'cheque_number' => $c->cheque_number, 'currency' => $c->currency, 'amount' => (string) $c->amount,
                'reference' => "Post-dated cheque {$c->cheque_number}" . ($c->drawn_on ? " ({$c->drawn_on})" : '')];
            $payment = $c->direction === 'in' ? $receive->handle($body, $request->user()->getKey()) : $pay->handle($body, $request->user()->getKey());
            DB::table('post_dated_cheques')->where(['company_id' => $c->company_id, 'id' => $c->id])
                ->update(['status' => 'presented', 'payment_id' => $payment->getKey(), 'closed_on' => $date, 'updated_at' => now()]);
            $this->audit->record($c->company_id, 'post_dated_cheque_presented', 'post_dated_cheque', $c->id, ['status' => 'held'],
                ['status' => 'presented', 'payment_id' => $payment->getKey(), 'date' => $date], userId: $request->user()->getKey());

            return $payment;
        });

        return response()->json([...$this->one($c->id), 'payment_id' => $payment->getKey()]);
    }

    /** Returned to the customer, or torn up: it never becomes a payment. */
    public function cancel(Request $request, string $cheque): JsonResponse
    {
        $this->authorizeCompany($this->permissionFor($cheque));
        $c = $this->held($cheque);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        $changed = DB::table('post_dated_cheques')->where(['company_id' => $c->company_id, 'id' => $c->id, 'status' => 'held'])
            ->update(['status' => 'cancelled', 'closed_on' => max($this->current->today(), (string) $c->handed_on), 'updated_at' => now()]);
        if ($changed === 0) {   // presented or cancelled by someone else a moment ago: nothing is recorded
            throw ValidationException::withMessages(['cheque' => ["Cheque {$c->cheque_number} was presented or cancelled already."]]);
        }
        $this->audit->record($c->company_id, 'post_dated_cheque_cancelled', 'post_dated_cheque', $c->id, ['status' => 'held'], ['status' => 'cancelled'],
            reason: $reason, userId: $request->user()->getKey());

        return response()->json($this->one($c->id));
    }

    /** The permission a cheque's side needs: received, payments.receive; written, payments.pay. Not found: receive (then 404). */
    private function permissionFor(string $id): string
    {
        $direction = Str::isUuid($id) ? DB::table('post_dated_cheques')->where(['company_id' => $this->current->id(), 'id' => $id])->value('direction') : null;

        return $direction === 'out' ? 'payments.pay' : 'payments.receive';
    }

    private function held(string $id): object
    {
        $c = Str::isUuid($id) ? DB::table('post_dated_cheques')->where(['company_id' => $this->current->id(), 'id' => $id])->first() : null;
        if ($c === null) {
            abort(404);
        }
        if ($c->status !== 'held') {
            throw ValidationException::withMessages(['cheque' => ["Cheque {$c->cheque_number} was {$c->status} already."]]);
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private function one(string $id): array
    {
        $c = DB::table('post_dated_cheques')->where(['company_id' => $this->current->id(), 'id' => $id])->first();

        return ['id' => $c->id, 'direction' => $c->direction, 'contact_id' => $c->contact_id, 'account_id' => $c->account_id, 'cheque_number' => $c->cheque_number,
            'drawn_on' => $c->drawn_on, 'cheque_date' => $c->cheque_date, 'handed_on' => $c->handed_on, 'currency' => $c->currency,
            'amount' => (string) $c->amount, 'note' => $c->note, 'status' => $c->status, 'payment_id' => $c->payment_id, 'closed_on' => $c->closed_on];
    }
}
