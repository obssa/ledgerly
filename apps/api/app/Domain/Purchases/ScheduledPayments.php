<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Purchases\Actions\PayBills;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 3: scheduled supplier payments (103). A payment planned for a later day: the supplier, which of their bills
 * and how much of each, from which money account. It is a plan, not money: nothing is in the books until someone
 * confirms, on or after the day, that the money went out. Confirming records an ordinary payment made through PayBills
 * (with any tax withheld, a fee, a cheque number), and the plan is closed as paid. Every id from a request is loaded
 * through row-level security and every query names the company.
 */
final class ScheduledPayments
{
    /** Accounts money can be paid from. */
    public const MONEY = ['bank', 'cash', 'mobile_money'];

    public const MAX_BILLS = 200;

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit, private readonly PayBills $pay)
    {
    }

    /**
     * @param array{contact_id: string, account_id: string, pay_on: string, method: string, currency: string, reference?: ?string,
     *              note?: ?string, bills: list<array{bill_id: string, amount?: ?string}>} $data
     */
    public function schedule(array $data, string $userId): array
    {
        $companyId = $this->current->id();
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($data, $userId, $companyId, $id) {
            $supplier = Contact::query()->where('is_vendor', true)->find($data['contact_id'])
                ?? throw ValidationException::withMessages(['contact_id' => ['Not a supplier of this company.']]);
            $this->account($data['account_id'], $data['currency']);
            $lines = $this->lines($data['bills'], $supplier, $data['currency'], null);
            $this->write(fn () => DB::table('scheduled_payments')->insert([
                'id' => $id, 'company_id' => $companyId, 'contact_id' => $supplier->getKey(), 'account_id' => $data['account_id'],
                'pay_on' => $data['pay_on'], 'method' => $data['method'], 'currency' => $data['currency'],
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $userId,
            ]));
            $this->insertLines($companyId, $id, $lines);
            $this->audit->record($companyId, 'payment_scheduled', 'scheduled_payment', $id, null, [
                'supplier' => $supplier->name, 'pay_on' => $data['pay_on'], 'method' => $data['method'], 'currency' => $data['currency'],
                'amount' => $this->sum($lines, $data['currency']), 'bills' => $this->billsForAudit($lines),
            ], userId: $userId);
        });

        return $this->one($id);
    }

    /**
     * While it is scheduled: another day, account, method, reference or note, and another set of bills or amounts.
     *
     * @param array{account_id: string, pay_on: string, method: string, reference?: ?string, note?: ?string,
     *              bills: list<array{bill_id: string, amount?: ?string}>} $data
     */
    public function update(string $id, array $data, string $userId): array
    {
        $companyId = $this->current->id();

        DB::transaction(function () use ($id, $data, $userId, $companyId) {
            $plan = $this->scheduled($id, lock: true);
            $supplier = Contact::query()->findOrFail($plan->contact_id);
            $this->account($data['account_id'], $plan->currency);
            $before = $this->snapshot($plan);
            $lines = $this->lines($data['bills'], $supplier, $plan->currency, $plan->id);
            $this->write(fn () => DB::table('scheduled_payments')->where(['company_id' => $companyId, 'id' => $plan->id])->update([
                'account_id' => $data['account_id'], 'pay_on' => $data['pay_on'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'updated_at' => now(),
            ]));
            DB::table('scheduled_payment_bills')->where(['company_id' => $companyId, 'scheduled_payment_id' => $plan->id])->delete();
            $this->insertLines($companyId, $plan->id, $lines);
            $after = $this->snapshot($this->scheduled($plan->id));
            $changes = AuditLogger::diff($before, $after);
            if ($changes !== []) {
                $this->audit->record($companyId, 'scheduled_payment_changed', 'scheduled_payment', $plan->id,
                    array_map(fn ($c) => $c['old'], $changes), array_map(fn ($c) => $c['new'], $changes), userId: $userId);
            }
        });

        return $this->one($id);
    }

    /**
     * The money went out: records the payment made, through the usual payment rules, and closes the plan as paid. The
     * bills and their amounts are the plan's; the day (today unless given), any tax withheld (with its certificate), a
     * bank fee, a cheque number and the exchange rate are told now.
     *
     * @param array{payment_date: string, reference?: ?string, fee?: ?string, withholding?: ?string, withholding_certificate?: ?string,
     *              cheque_number?: ?string, fx_rate?: ?string} $data
     */
    public function confirm(string $id, array $data, string $userId): array
    {
        $companyId = $this->current->id();

        $paymentId = DB::transaction(function () use ($id, $data, $userId, $companyId) {
            $plan = $this->scheduled($id, lock: true);
            $lines = DB::table('scheduled_payment_bills')->where(['company_id' => $companyId, 'scheduled_payment_id' => $plan->id])
                ->orderBy('id')->get(['document_id', 'amount']);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['bills' => ['This scheduled payment has no bills: edit it, or cancel it.']]);
            }
            $planned = $lines->reduce(fn (Money $s, $l) => $s->plus(Money::of((string) $l->amount, $plan->currency)), Money::zero($plan->currency));
            $withholding = Money::of($data['withholding'] ?? '0', $plan->currency);
            $cash = $planned->minus($withholding);
            if ($cash->isNegative() || $cash->isZero()) {
                throw ValidationException::withMessages(['withholding' => ["The tax withheld is less than the {$plan->currency} {$planned->toDecimalString()} planned."]]);
            }
            $body = [
                'contact_id' => $plan->contact_id, 'account_id' => $plan->account_id, 'payment_date' => $data['payment_date'],
                'method' => $plan->method, 'reference' => ($data['reference'] ?? null) ?: $plan->reference, 'currency' => $plan->currency,
                'fx_rate' => $data['fx_rate'] ?? null, 'account_amount' => $data['account_amount'] ?? null, 'amount' => $cash->toDecimalString(), 'fee' => $data['fee'] ?? null,
                'withholding' => $withholding->toDecimalString(), 'withholding_certificate' => $data['withholding_certificate'] ?? null,
                'cheque_number' => $data['cheque_number'] ?? null,
                'allocations' => $lines->map(fn ($l) => ['bill_id' => $l->document_id, 'amount' => (string) $l->amount])->all(),
            ];
            ChequeNumbers::assertWritable($body);
            $payment = $this->pay->handle($body, $userId);
            DB::table('scheduled_payments')->where(['company_id' => $companyId, 'id' => $plan->id])
                ->update(['status' => 'paid', 'payment_id' => $payment->getKey(), 'closed_on' => $data['payment_date'], 'updated_at' => now()]);
            $this->audit->record($companyId, 'scheduled_payment_confirmed', 'scheduled_payment', $plan->id, ['status' => 'scheduled'],
                ['status' => 'paid', 'payment_id' => $payment->getKey(), 'payment_date' => $data['payment_date'], 'amount' => $cash->toDecimalString(),
                    'withholding' => $withholding->toDecimalString()], userId: $userId);

            return $payment->getKey();
        });

        return [...$this->one($id), 'payment_id' => $paymentId];
    }

    /** The payment will not be made (or was made another way): closed, with a reason; nothing was in the books. */
    public function cancel(string $id, string $reason, string $userId): array
    {
        $companyId = $this->current->id();
        $plan = $this->scheduled($id);
        $changed = DB::table('scheduled_payments')->where(['company_id' => $companyId, 'id' => $plan->id, 'status' => 'scheduled'])
            ->update(['status' => 'cancelled', 'closed_on' => $this->current->today(), 'updated_at' => now()]);
        if ($changed === 0) {   // confirmed or cancelled by someone else a moment ago: nothing is recorded
            throw ValidationException::withMessages(['scheduled_payment' => ['This payment was confirmed or cancelled already.']]);
        }
        $this->audit->record($companyId, 'scheduled_payment_cancelled', 'scheduled_payment', $plan->id, ['status' => 'scheduled'], ['status' => 'cancelled'],
            reason: $reason, userId: $userId);

        return $this->one($plan->id);
    }

    /** @return list<array<string, mixed>> scheduled first (by day), then the closed ones, newest first */
    public function list(?string $status, ?string $contactId): array
    {
        $companyId = $this->current->id();
        $rows = $this->query()
            ->when($status, fn ($q, $s) => $q->where('s.status', $s))
            ->when($contactId, fn ($q, $c) => $q->where('s.contact_id', $c))
            ->orderByRaw("CASE WHEN s.status = 'scheduled' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN s.status = 'scheduled' THEN s.pay_on END ASC")
            ->orderByDesc('s.closed_on')->orderBy('s.id')->limit(500)->get();
        $amounts = DB::table('scheduled_payment_bills')->where('company_id', $companyId)->whereIn('scheduled_payment_id', $rows->pluck('id'))
            ->groupBy('scheduled_payment_id')->get(['scheduled_payment_id', DB::raw('sum(amount)::numeric(20,2)::text AS amount'), DB::raw('count(*) AS bills')])
            ->keyBy('scheduled_payment_id');

        return $rows->map(fn ($r) => $this->present($r, $amounts[$r->id] ?? null))->values()->all();
    }

    /** @return array<string, mixed> */
    public function one(string $id): array
    {
        $companyId = $this->current->id();
        $r = Str::isUuid($id) ? $this->query()->where('s.id', $id)->first() : null;
        if ($r === null) {
            abort(404);
        }
        $bills = DB::table('scheduled_payment_bills as b')->join('documents as d', 'd.id', '=', 'b.document_id')
            ->where('b.company_id', $companyId)->where('b.scheduled_payment_id', $r->id)->orderBy('d.issue_date')->orderBy('d.id')
            ->get(['d.id', 'd.number', 'd.reference', 'd.issue_date', 'd.due_date', 'd.subtotal', 'd.total', 'd.amount_due', 'd.status', 'b.amount']);
        $sum = $bills->reduce(fn (string $s, $b) => bcadd($s, (string) $b->amount, 2), '0.00');

        return [
            ...$this->present($r, (object) ['amount' => $sum, 'bills' => $bills->count()]),
            'bills' => $bills->map(fn ($b) => ['id' => $b->id, 'number' => $b->number, 'reference' => $b->reference, 'issue_date' => $b->issue_date,
                'due_date' => $b->due_date, 'subtotal' => (string) $b->subtotal, 'total' => (string) $b->total, 'amount_due' => (string) $b->amount_due, 'status' => $b->status,
                'amount' => (string) $b->amount])->values(),
        ];
    }

    /** What is planned for a bill and not paid yet: the scheduled payment that has it, or null. */
    public function forBill(string $billId): ?array
    {
        $row = DB::table('scheduled_payment_bills as b')->join('scheduled_payments as s', 's.id', '=', 'b.scheduled_payment_id')
            ->where(['b.company_id' => $this->current->id(), 'b.document_id' => $billId, 's.status' => 'scheduled'])
            ->first(['s.id', 's.pay_on', 'b.amount']);

        return $row === null ? null : ['id' => $row->id, 'pay_on' => substr((string) $row->pay_on, 0, 10), 'amount' => (string) $row->amount];
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('scheduled_payments as s')->join('contacts as k', 'k.id', '=', 's.contact_id')->join('accounts as a', 'a.id', '=', 's.account_id')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.company_id', $this->current->id())
            ->select(['s.id', 's.contact_id', 'k.name as contact_name', 's.account_id', 'a.code as account_code', 'a.name as account_name', 'a.currency as account_currency', 's.pay_on',
                's.method', 's.reference', 's.note', 's.currency', 's.status', 's.payment_id', 's.closed_on', 's.reminded_on', 's.created_at',
                'u.name as created_by_name']);
    }

    /** @return array<string, mixed> */
    private function present(object $r, ?object $sum): array
    {
        $payOn = substr((string) $r->pay_on, 0, 10);

        return [
            'id' => $r->id, 'contact' => ['id' => $r->contact_id, 'name' => $r->contact_name],
            // The account's own currency (null: the company's), so confirming through a birr account asks what it paid.
            'account' => ['id' => $r->account_id, 'code' => $r->account_code, 'name' => $r->account_name, 'currency' => $r->account_currency],
            'pay_on' => $payOn, 'method' => $r->method, 'reference' => $r->reference, 'note' => $r->note, 'currency' => $r->currency,
            'amount' => $sum?->amount ?? '0.00', 'bill_count' => (int) ($sum?->bills ?? 0), 'status' => $r->status, 'payment_id' => $r->payment_id,
            'closed_on' => $r->closed_on === null ? null : substr((string) $r->closed_on, 0, 10),
            'due' => $r->status === 'scheduled' && $payOn <= $this->current->today(),
            'created_by' => $r->created_by_name, 'created_at' => $r->created_at,
        ];
    }

    private function scheduled(string $id, bool $lock = false): object
    {
        $plan = Str::isUuid($id) ? DB::table('scheduled_payments')->where(['company_id' => $this->current->id(), 'id' => $id])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if ($plan === null) {
            abort(404);
        }
        if ($plan->status !== 'scheduled') {
            throw ValidationException::withMessages(['scheduled_payment' => ["This payment was {$plan->status} already."]]);
        }

        return $plan;
    }

    private function account(string $accountId, string $currency): void
    {
        $company = $this->current->get();
        $account = Account::query()->where('is_active', true)->whereIn('subtype', self::MONEY)->find($accountId)
            ?? throw ValidationException::withMessages(['account_id' => ['Choose a bank, cash or mobile-money account of this company.']]);
        $held = $account->currency ?? $company->base_currency;
        if ($held !== $currency && $held !== $company->base_currency) {
            throw ValidationException::withMessages(['account_id' => ["{$account->name} is kept in {$held}: a {$currency} payment goes out of an account in {$currency} or in {$company->base_currency}."]]);
        }
    }

    /**
     * The bills to plan, locked in a fixed order so two plans never take the same bill at once: each this supplier's open
     * bill in this currency, not already in another scheduled payment, with no more than is still to pay (all of it when
     * no amount is given).
     *
     * @param list<array{bill_id: string, amount?: ?string}> $requested
     * @return list<array{bill: Document, amount: Money}>
     */
    private function lines(array $requested, Contact $supplier, string $currency, ?string $planId): array
    {
        $bills = Document::query()->whereIn('id', array_column($requested, 'bill_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $taken = DB::table('scheduled_payment_bills as b')->join('scheduled_payments as s', 's.id', '=', 'b.scheduled_payment_id')
            ->where(['b.company_id' => $this->current->id(), 's.status' => 'scheduled'])->whereIn('b.document_id', $bills->keys())
            ->when($planId, fn ($q, $p) => $q->where('s.id', '!=', $p))->pluck('s.pay_on', 'b.document_id');

        $out = [];
        $errors = [];
        foreach (array_values($requested) as $i => $r) {
            $bill = $bills[$r['bill_id']] ?? null;
            if ($bill === null || $bill->type !== 'bill' || $bill->contact_id !== $supplier->getKey()) {
                $errors["bills.{$i}.bill_id"] = ["Not a bill of {$supplier->name}."];
                continue;
            }
            $amount = Money::of(($r['amount'] ?? null) ?: (string) $bill->amount_due, $currency);
            if (! in_array($bill->status, ['posted', 'part_paid'], true)) {
                $errors["bills.{$i}.bill_id"] = ["{$bill->label()} is not open for payment."];
            } elseif ($bill->currency !== $currency) {
                $errors["bills.{$i}.bill_id"] = ["{$bill->number} is in {$bill->currency}, not {$currency}."];
            } elseif (isset($taken[$bill->getKey()])) {
                $errors["bills.{$i}.bill_id"] = ["{$bill->number} is already in a payment scheduled for " . substr((string) $taken[$bill->getKey()], 0, 10) . '.'];
            } elseif ($amount->isZero() || $amount->isNegative()) {
                $errors["bills.{$i}.amount"] = ['Enter an amount above zero.'];
            } elseif (Money::of((string) $bill->amount_due, $currency)->minus($amount)->isNegative()) {
                $errors["bills.{$i}.amount"] = ["Only {$bill->amount_due} is still to pay on {$bill->number}."];
            } else {
                $out[] = ['bill' => $bill, 'amount' => $amount];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @param list<array{bill: Document, amount: Money}> $lines */
    private function insertLines(string $companyId, string $planId, array $lines): void
    {
        foreach ($lines as $l) {
            $this->write(fn () => DB::table('scheduled_payment_bills')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $companyId, 'scheduled_payment_id' => $planId,
                'document_id' => $l['bill']->getKey(), 'amount' => $l['amount']->toDecimalString(),
            ]));
        }
    }

    /** A database rule that refuses the write is told in words (a bill planned twice by two people at once, say). */
    private function write(callable $fn): void
    {
        try {
            $fn();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23505', '23514'], true)) {
                throw ValidationException::withMessages(['bills' => [$e->getCode() === '23505'
                    ? 'One of these bills is already in another scheduled payment.' : 'The payment does not fit its supplier, account or bills.']]);
            }
            throw $e;
        }
    }

    /** @param list<array{bill: Document, amount: Money}> $lines */
    private function sum(array $lines, string $currency): string
    {
        return array_reduce($lines, fn (Money $s, array $l) => $s->plus($l['amount']), Money::zero($currency))->toDecimalString();
    }

    /** @param list<array{bill: Document, amount: Money}> $lines @return list<array{bill: ?string, amount: string}> */
    private function billsForAudit(array $lines): array
    {
        return array_map(fn (array $l) => ['bill' => $l['bill']->number, 'amount' => $l['amount']->toDecimalString()], $lines);
    }

    /** @return array<string, mixed> the plan as the history shows it */
    private function snapshot(object $plan): array
    {
        $bills = DB::table('scheduled_payment_bills as b')->join('documents as d', 'd.id', '=', 'b.document_id')
            ->where(['b.company_id' => $this->current->id(), 'b.scheduled_payment_id' => $plan->id])->orderBy('d.number')
            ->get(['d.number', 'b.amount'])->map(fn ($b) => "{$b->number}: {$b->amount}")->implode(', ');

        return ['pay_on' => substr((string) $plan->pay_on, 0, 10), 'account_id' => $plan->account_id, 'method' => $plan->method,
            'reference' => $plan->reference, 'note' => $plan->note, 'bills' => $bills];
    }
}
