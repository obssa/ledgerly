<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\Rounding;
use App\Domain\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leases, as the lessee (Module 9, 095; IFRS for SMEs section 20). Equal monthly payments, in arrears or in advance.
 * A finance lease is recognised at the present value of its payments at its rate (Dr the asset, registered on the
 * fixed asset register at that cost; Cr the lease liability), and each payment is split by the effective interest
 * method: interest on what is owed since the last payment, the rest off the liability, the last clearing it. An
 * operating lease is an expense as each payment is made.
 */
final class Leases
{
    public function __construct(private readonly PostingService $posting)
    {
    }

    /** The present value of $months payments of $payment at $annualRate a year, monthly, to the cent. */
    public static function presentValue(string $payment, int $months, string $annualRate, bool $inAdvance): string
    {
        $r = BigDecimal::of($annualRate)->dividedBy(12, 20, Rounding::halfUp());
        $factor = BigDecimal::one()->plus($r);
        $discount = BigDecimal::one();
        $sum = BigDecimal::zero();
        for ($k = 1; $k <= $months; $k++) {
            if ($inAdvance) {
                $sum = $sum->plus($discount);   // paid at the start of month k: discounted k - 1 months
            }
            $discount = $discount->dividedBy($factor, 20, Rounding::halfUp());
            if (! $inAdvance) {
                $sum = $sum->plus($discount);   // paid at its end: k months
            }
        }

        return (string) BigDecimal::of($payment)->multipliedBy($sum)->toScale(2, Rounding::halfUp());
    }

    /** The day $k months after $date, kept to the month's last day where the month is shorter. */
    public static function addMonths(string $date, int $k): string
    {
        $d = new DateTimeImmutable($date);
        $first = $d->modify('first day of this month')->modify("+{$k} months");

        return $first->format('Y-m-') . str_pad((string) min((int) $d->format('d'), (int) $first->format('t')), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The lease's payments, made and to come: when each is due, its interest and principal, and what is owed after.
     *
     * @return list<array{number: int, due_on: string, amount: string, interest: string, principal: string, owed: string, paid_on: ?string, entry_id: ?string}>
     */
    public function schedule(object $lease): array
    {
        $paid = DB::table('lease_payments')->where(['company_id' => $lease->company_id, 'lease_id' => $lease->id])->orderBy('number')->get()->keyBy('number');
        $finance = $lease->kind === 'finance';
        $owed = BigDecimal::of($finance ? (string) $lease->present_value : '0');
        $rows = [];
        for ($k = 1; $k <= (int) $lease->months; $k++) {
            $p = $paid[$k] ?? null;
            [$interest, $principal] = $p !== null ? [BigDecimal::of((string) $p->interest), BigDecimal::of((string) $p->principal)] : $this->split($lease, $k, $owed);
            $owed = $owed->minus($principal);
            $rows[] = ['number' => $k,
                'due_on' => $lease->in_advance ? self::addMonths((string) $lease->starts_on, $k - 1)
                    : (new DateTimeImmutable(self::addMonths((string) $lease->starts_on, $k)))->modify('-1 day')->format('Y-m-d'),
                'amount' => (string) BigDecimal::of((string) $lease->payment)->toScale(2), 'interest' => (string) $interest->toScale(2),
                'principal' => (string) $principal->toScale(2), 'owed' => (string) $owed->toScale(2),
                'paid_on' => $p?->paid_on, 'entry_id' => $p?->entry_id];
        }

        return $rows;
    }

    /** @return array{0: BigDecimal, 1: BigDecimal} payment $k's interest and principal, given what is owed before it */
    private function split(object $lease, int $k, BigDecimal $owed): array
    {
        if ($lease->kind !== 'finance') {
            return [BigDecimal::zero(), BigDecimal::zero()];
        }
        $amount = BigDecimal::of((string) $lease->payment);
        if ($k === (int) $lease->months) {
            return [$amount->minus($owed), $owed];
        }
        $interest = $k === 1 && $lease->in_advance ? BigDecimal::zero()
            : $owed->multipliedBy((string) $lease->annual_rate)->dividedBy(12, 2, Rounding::halfUp());

        return [$interest, $amount->minus($interest)];
    }

    /**
     * Sets a lease up. A finance lease registers its asset at the present value (straight line over $usefulLife months)
     * and posts Dr the asset, Cr the lease liability on its first day.
     *
     * @param array<string, mixed> $data
     */
    public function create(Company $company, array $data, string $userId): string
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $id = (string) Str::uuid7();
            $base = $company->base_currency;
            $row = ['id' => $id, 'company_id' => $company->getKey(), 'code' => $data['code'], 'name' => trim((string) $data['name']), 'lessor_id' => $data['lessor_id'] ?? null,
                'kind' => $data['kind'], 'starts_on' => $data['starts_on'], 'months' => (int) $data['months'], 'payment' => (string) $data['payment'],
                'in_advance' => (bool) ($data['in_advance'] ?? false), 'created_by' => $userId];
            if ($data['kind'] === 'operating') {
                DB::table('leases')->insert([...$row, 'expense_account_id' => $data['expense_account_id']]);

                return $id;
            }
            $pv = self::presentValue((string) $data['payment'], (int) $data['months'], (string) $data['annual_rate'], (bool) ($data['in_advance'] ?? false));
            $assetId = (string) Str::uuid7();
            DB::table('fixed_assets')->insert(['id' => $assetId, 'company_id' => $company->getKey(), 'code' => $data['code'], 'name' => trim((string) $data['name']),
                'asset_account_id' => $data['asset_account_id'], 'depreciation_account_id' => $data['depreciation_account_id'],
                'expense_account_id' => $data['depreciation_expense_account_id'], 'acquired_on' => $data['starts_on'], 'cost' => $pv,
                'method' => 'straight_line', 'useful_life_months' => (int) ($data['useful_life_months'] ?? $data['months']),
                'depreciation_starts_on' => $data['starts_on'], 'created_by' => $userId]);
            $money = Money::of($pv, $base);
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable((string) $data['starts_on']), $base, $base, '1',
                mb_substr("Finance lease {$data['code']}: {$data['name']}", 0, 255), 'lease', $id);
            $draft->add(JournalLineDraft::debit($data['asset_account_id'], $money, null, "Leased asset {$data['code']}", $data['lessor_id'] ?? null));
            $draft->add(JournalLineDraft::credit($data['liability_account_id'], $money, null, "Lease liability {$data['code']}", $data['lessor_id'] ?? null));
            $entry = $this->posting->post($draft, $userId);
            DB::table('leases')->insert([...$row, 'annual_rate' => (string) $data['annual_rate'], 'present_value' => $pv,
                'liability_account_id' => $data['liability_account_id'], 'interest_account_id' => $data['interest_account_id'],
                'fixed_asset_id' => $assetId, 'commencement_entry_id' => $entry->getKey()]);

            return $id;
        });
    }

    /**
     * Pays the next payment on $date from a bank, wallet or cash account: a finance lease's interest to its interest
     * account and the rest off its liability; an operating lease's whole payment to its expense.
     *
     * @return array{number: int, amount: string, interest: string, principal: string, entry: array{id: string, number: int}}
     */
    public function pay(Company $company, string $leaseId, string $date, string $bankAccountId, string $userId): array
    {
        return DB::transaction(function () use ($company, $leaseId, $date, $bankAccountId, $userId) {
            $l = DB::table('leases')->where(['company_id' => $company->getKey(), 'id' => $leaseId])->lockForUpdate()->first() ?? abort(404);
            if ($l->status !== 'active') {
                throw ValidationException::withMessages(['date' => ["Lease {$l->code} has ended."]]);
            }
            if ($date < (string) $l->starts_on) {
                throw ValidationException::withMessages(['date' => ["Lease {$l->code} starts on {$l->starts_on}."]]);
            }
            $next = collect($this->schedule($l))->firstWhere('paid_on', null);
            if ($next === null) {
                throw ValidationException::withMessages(['date' => ["Lease {$l->code} is paid in full."]]);
            }
            $base = $company->base_currency;
            $bank = Account::query()->whereKey($bankAccountId)->whereIn('subtype', ['bank', 'cash', 'mobile_money'])->where('is_active', true)->first();
            if ($bank === null || ($bank->currency !== null && $bank->currency !== $base)) {
                throw ValidationException::withMessages(['bank_account_id' => ["Choose the bank, wallet or cash account it is paid from, in {$base}."]]);
            }
            $paymentId = (string) Str::uuid7();
            $label = "Lease {$l->code} payment {$next['number']} of {$l->months}";
            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $base, '1', mb_substr("{$label}: {$l->name}", 0, 255), 'lease_payment', $paymentId);
            if ($l->kind === 'finance') {
                if (BigDecimal::of($next['principal'])->isPositive()) {
                    $draft->add(JournalLineDraft::debit($l->liability_account_id, Money::of($next['principal'], $base), null, $label, $l->lessor_id));
                }
                if (BigDecimal::of($next['interest'])->isPositive()) {
                    $draft->add(JournalLineDraft::debit($l->interest_account_id, Money::of($next['interest'], $base), null, "Interest on {$label}", $l->lessor_id));
                }
            } else {
                $draft->add(JournalLineDraft::debit($l->expense_account_id, Money::of($next['amount'], $base), null, $label, $l->lessor_id));
            }
            $draft->add(JournalLineDraft::credit($bank->getKey(), Money::of($next['amount'], $base), null, $label, $l->lessor_id));
            $entry = $this->posting->post($draft, $userId);
            DB::table('lease_payments')->insert(['id' => $paymentId, 'company_id' => $company->getKey(), 'lease_id' => $l->id, 'number' => $next['number'],
                'paid_on' => $date, 'amount' => $next['amount'], 'interest' => $l->kind === 'finance' ? $next['interest'] : '0',
                'principal' => $l->kind === 'finance' ? $next['principal'] : '0', 'entry_id' => $entry->getKey(), 'created_by' => $userId]);

            return ['number' => $next['number'], 'amount' => $next['amount'], 'interest' => $l->kind === 'finance' ? $next['interest'] : '0.00',
                'principal' => $l->kind === 'finance' ? $next['principal'] : '0.00', 'entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number]];
        });
    }
}
