<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
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
 * Fixed assets (Module 9c): each month's depreciation charge and the monthly run that posts it (042).
 *
 *   straight line     (cost - residual value) / useful life, every month from the start
 *   reducing balance  book value (cost less all depreciation so far) x annual rate / 12
 *
 * Either way an asset stops at its residual value: the last charge is what is left. Runs go forward one month end at a
 * time (no month before the latest run), one entry for the month, two lines per asset (Dr its depreciation expense,
 * Cr its accumulated depreciation), each with the asset's location.
 */
final class FixedAssets
{
    public function __construct(private readonly PostingService $posting)
    {
    }

    private static function positive(Money $m): bool
    {
        return ! $m->isZero() && ! $m->isNegative();
    }

    /** The month end on or after a date. */
    public static function monthEnd(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Each active asset's charge for the month ending $periodEnd (assets with nothing to charge left out).
     *
     * @return list<array{asset: object, amount: string}>
     */
    public function charges(Company $company, string $periodEnd): array
    {
        $id = $company->getKey();
        $assets = DB::table('fixed_assets')->where(['company_id' => $id, 'status' => 'active'])
            ->where('depreciation_starts_on', '<=', $periodEnd)->orderBy('code')->get();
        $soFar = DB::table('asset_depreciation')->where('company_id', $id)->whereIn('asset_id', $assets->pluck('id')->all())
            ->groupBy('asset_id')->select('asset_id')->selectRaw('sum(amount)::numeric(20,2)::text AS total')->pluck('total', 'asset_id');
        $months = DB::table('asset_depreciation')->where('company_id', $id)->whereIn('asset_id', $assets->pluck('id')->all())
            ->groupBy('asset_id')->select('asset_id')->selectRaw('count(*) AS n')->pluck('n', 'asset_id');
        $impaired = $this->impaired($id, $assets->pluck('id')->all());
        $out = [];
        foreach ($assets as $a) {
            $amount = $this->charge($a, (string) ($soFar[$a->id] ?? '0'), (string) ($impaired[$a->id] ?? '0'), (int) ($months[$a->id] ?? 0));
            if ($amount->isPositive()) {
                $out[] = ['asset' => $a, 'amount' => (string) $amount];
            }
        }

        return $out;
    }

    /**
     * One asset's charge for a month, given what it has been charged so far. 091: an impaired asset's lower carrying
     * amount is depreciated over its remaining life (straight line: what is left over the months still to go), as
     * IFRS for SMEs 27 asks; an asset never impaired is charged as before.
     */
    public function charge(object $a, string $soFar, string $impaired = '0', int $monthsCharged = 0): BigDecimal
    {
        $cost = BigDecimal::of((string) $a->cost);
        $residual = BigDecimal::of((string) $a->residual_value);
        $depreciated = BigDecimal::of((string) $a->opening_depreciation)->plus($soFar)->plus($impaired);
        $left = $cost->minus($residual)->minus($depreciated);
        if (! $left->isPositive()) {
            return BigDecimal::zero()->toScale(2);
        }
        $writtenDown = BigDecimal::of($impaired)->isPositive();
        $monthly = match (true) {
            $a->method === 'straight_line' && $writtenDown => $left->dividedBy(max(1, (int) $a->useful_life_months - $monthsCharged), 2, Rounding::halfUp()),
            $a->method === 'straight_line' => $cost->minus($residual)->dividedBy((int) $a->useful_life_months, 2, Rounding::halfUp()),
            default => $cost->minus($depreciated)->multipliedBy((string) $a->annual_rate)->dividedBy(12, 2, Rounding::halfUp()),
        };

        return ($monthly->isGreaterThan($left) ? $left : $monthly)->toScale(2);
    }

    /**
     * Post the depreciation for the month ending $periodEnd.
     *
     * @return array{run: string, entry: array{id: string, number: int}, total: string, assets: int}
     */
    public function run(Company $company, string $periodEnd, string $userId): array
    {
        return DB::transaction(function () use ($company, $periodEnd, $userId) {
            $id = $company->getKey();
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["depreciation:{$id}"]);   // one run at a time per company
            $latest = DB::table('depreciation_runs')->where('company_id', $id)->max('period_end');
            if ($latest !== null && $periodEnd <= $latest) {
                throw ValidationException::withMessages(['period_end' => ["Depreciation is posted to {$latest}: runs go forward, the next is for a later month."]]);
            }
            $charges = $this->charges($company, $periodEnd);
            if ($charges === []) {
                throw ValidationException::withMessages(['period_end' => ["Nothing to depreciate for the month to {$periodEnd}."]]);
            }
            $base = $company->base_currency;
            $runId = (string) Str::uuid7();
            $draft = new JournalDraft($id, new DateTimeImmutable($periodEnd), $base, $base, '1', "Depreciation for the month to {$periodEnd}", 'depreciation', $runId);
            $total = Money::zero($base);
            foreach ($charges as ['asset' => $a, 'amount' => $amount]) {
                $money = Money::of($amount, $base);
                $dims = $a->location_id ? ['location_id' => $a->location_id] : [];
                $draft->add(JournalLineDraft::debit($a->expense_account_id, $money, null, "{$a->code} {$a->name}", null, $dims));
                $draft->add(JournalLineDraft::credit($a->depreciation_account_id, $money, null, "{$a->code} {$a->name}", null, $dims));
                $total = $total->plus($money);
            }
            $entry = $this->posting->post($draft, $userId);
            DB::table('depreciation_runs')->insert(['id' => $runId, 'company_id' => $id, 'period_end' => $periodEnd, 'journal_entry_id' => $entry->getKey(),
                'total' => $total->toDecimalString(), 'created_by' => $userId]);
            DB::table('asset_depreciation')->insert(array_map(fn (array $c) => ['company_id' => $id, 'run_id' => $runId, 'asset_id' => $c['asset']->id, 'amount' => $c['amount']], $charges));

            return ['run' => $runId, 'entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number], 'total' => $total->toDecimalString(), 'assets' => count($charges)];
        });
    }

    /**
     * Dispose of an asset (sold or scrapped) on a day: one entry takes off its accumulated depreciation and its cost,
     * brings in what it was sold for (to a bank, wallet or cash account), and posts the difference from its book value
     * as a gain or loss on disposal (043). Depreciation for the months before should be posted first.
     *
     * @return array{entry: array{id: string, number: int}, book_value: string, proceeds: string, gain: string}
     */
    public function dispose(Company $company, string $assetId, string $date, string $proceeds, ?string $bankAccountId, string $userId): array
    {
        return DB::transaction(function () use ($company, $assetId, $date, $proceeds, $bankAccountId, $userId) {
            $id = $company->getKey();
            $base = $company->base_currency;
            $a = DB::table('fixed_assets')->where(['company_id' => $id, 'id' => $assetId])->lockForUpdate()->first();
            if ($a === null) {
                abort(404);
            }
            if ($a->status !== 'active') {
                throw ValidationException::withMessages(['date' => ["{$a->code} was disposed of on {$a->disposed_on}."]]);
            }
            if ($date < (string) $a->acquired_on) {
                throw ValidationException::withMessages(['date' => ["{$a->code} was acquired on {$a->acquired_on}: it goes on or after that day."]]);
            }
            $lastCharged = DB::table('asset_depreciation as d')->join('depreciation_runs as r', 'r.id', '=', 'd.run_id')
                ->where(['d.company_id' => $id, 'd.asset_id' => $a->id])->max('r.period_end');
            if ($lastCharged !== null && $date <= $lastCharged) {
                throw ValidationException::withMessages(['date' => ["Depreciation of {$a->code} is posted to {$lastCharged}: date the disposal after that."]]);
            }
            $charged = (string) DB::table('asset_depreciation')->where(['company_id' => $id, 'asset_id' => $a->id])->sum('amount');
            $impaired = (string) DB::table('asset_impairments')->where(['company_id' => $id, 'asset_id' => $a->id])->sum('amount');   // 091
            $accumulated = Money::of(BigDecimal::of((string) $a->opening_depreciation)->plus($charged ?: '0')->plus($impaired ?: '0'), $base);
            $cost = Money::of((string) $a->cost, $base);
            $received = Money::of($proceeds, $base);
            $book = $cost->minus($accumulated);
            $gain = $received->minus($book);
            $bank = null;
            if (self::positive($received)) {
                $bank = $bankAccountId === null ? null : \App\Domain\Ledger\Models\Account::query()->whereKey($bankAccountId)
                    ->whereIn('subtype', ['bank', 'cash', 'mobile_money'])->where('is_active', true)->first();
                if ($bank === null || ($bank->currency !== null && $bank->currency !== $base)) {
                    throw ValidationException::withMessages(['bank_account_id' => ["Choose the bank, wallet or cash account the {$received->toDecimalString()} came into, in {$base}."]]);
                }
            }
            $dims = $a->location_id ? ['location_id' => $a->location_id] : [];
            $label = "{$a->code} {$a->name}";
            $draft = new JournalDraft($id, new DateTimeImmutable($date), $base, $base, '1',
                (self::positive($received) ? 'Sale' : 'Disposal') . " of {$label}", 'asset_disposal', $a->id);
            if (self::positive($accumulated)) {
                $draft->add(JournalLineDraft::debit($a->depreciation_account_id, $accumulated, null, "Depreciation of {$label}", null, $dims));
            }
            if ($bank !== null) {
                $draft->add(JournalLineDraft::debit($bank->getKey(), $received, null, "Proceeds of {$label}", null, $dims));
            }
            $draft->add(JournalLineDraft::credit($a->asset_account_id, $cost, null, "Cost of {$label}", null, $dims));
            if (! $gain->isZero()) {
                $account = \App\Domain\Ledger\Models\Account::system('asset_disposal')->getKey();
                $draft->add(self::positive($gain)
                    ? JournalLineDraft::credit($account, $gain, null, "Gain on {$label}", null, $dims)
                    : JournalLineDraft::debit($account, $gain->negated(), null, "Loss on {$label}", null, $dims));
            }
            $entry = $this->posting->post($draft, $userId);
            DB::table('fixed_assets')->where(['company_id' => $id, 'id' => $a->id])->update([
                'status' => 'disposed', 'disposed_on' => $date, 'disposal_entry_id' => $entry->getKey(), 'proceeds' => $received->toDecimalString(), 'updated_at' => now(),
            ]);

            return ['entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number], 'book_value' => $book->toDecimalString(),
                'proceeds' => $received->toDecimalString(), 'gain' => $gain->toDecimalString()];
        });
    }

    /**
     * An asset's schedule: what each run charged, then the next months it would be charged (up to $ahead of them).
     *
     * @return array{posted: list<array<string, string>>, ahead: list<array<string, string>>, depreciated: string, book_value: string}
     */
    /** @param list<string> $assetIds @return array<string, string> what each asset has been written down by (091) */
    public function impaired(string $companyId, array $assetIds): array
    {
        return DB::table('asset_impairments')->where('company_id', $companyId)->whereIn('asset_id', $assetIds)->groupBy('asset_id')
            ->select('asset_id')->selectRaw('sum(amount)::numeric(20,2)::text AS total')->pluck('total', 'asset_id')->all();
    }

    /**
     * 091: writes an asset down by $amount on $date (Dr its depreciation and impairment expense, Cr its accumulated
     * depreciation), never below its residual value; later depreciation spreads what is left over the remaining life.
     *
     * @return array<string, mixed> the impairment
     */
    public function impair(Company $company, string $assetId, string $date, string $amount, string $reason, string $userId): array
    {
        return DB::transaction(function () use ($company, $assetId, $date, $amount, $reason, $userId) {
            $id = $company->getKey();
            $base = $company->base_currency;
            $a = DB::table('fixed_assets')->where(['company_id' => $id, 'id' => $assetId])->lockForUpdate()->first() ?? abort(404);
            if ($a->status !== 'active') {
                throw ValidationException::withMessages(['amount' => ["{$a->code} was disposed of: it is not written down."]]);
            }
            if ($date < (string) $a->acquired_on) {
                throw ValidationException::withMessages(['date' => ["{$a->code} was acquired on {$a->acquired_on}."]]);
            }
            $charged = (string) DB::table('asset_depreciation')->where(['company_id' => $id, 'asset_id' => $a->id])->sum('amount');
            $impaired = (string) ($this->impaired($id, [(string) $a->id])[$a->id] ?? '0');
            $left = BigDecimal::of((string) $a->cost)->minus((string) $a->residual_value)->minus((string) $a->opening_depreciation)->minus($charged ?: '0')->minus($impaired);
            if (BigDecimal::of($amount)->isGreaterThan($left)) {
                throw ValidationException::withMessages(['amount' => ["{$a->code} can be written down by {$left->toScale(2)} at most: its carrying amount above the residual value."]]);
            }
            $impairmentId = (string) Str::uuid7();
            $label = "{$a->code} {$a->name}";
            $dims = $a->location_id ? ['location_id' => $a->location_id] : [];
            $money = Money::of($amount, $base);
            $draft = new JournalDraft($id, new DateTimeImmutable($date), $base, $base, '1', mb_substr("Impairment of {$label}", 0, 255), 'asset_impairment', $impairmentId);
            $draft->add(JournalLineDraft::debit($a->expense_account_id, $money, null, "Impairment of {$label}", null, $dims));
            $draft->add(JournalLineDraft::credit($a->depreciation_account_id, $money, null, "Impairment of {$label}", null, $dims));
            $entry = $this->posting->post($draft, $userId);
            DB::table('asset_impairments')->insert(['id' => $impairmentId, 'company_id' => $id, 'asset_id' => $a->id, 'impaired_on' => $date,
                'amount' => $money->toDecimalString(), 'reason' => trim($reason), 'entry_id' => $entry->getKey(), 'created_by' => $userId]);

            return ['id' => $impairmentId, 'amount' => $money->toDecimalString(), 'impaired_on' => $date, 'entry' => ['id' => $entry->getKey(), 'number' => (int) $entry->number]];
        });
    }

    public function schedule(object $a, int $ahead = 12): array
    {
        $posted = DB::table('asset_depreciation as d')->join('depreciation_runs as r', 'r.id', '=', 'd.run_id')
            ->where(['d.company_id' => $a->company_id, 'd.asset_id' => $a->id])->orderBy('r.period_end')
            ->get(['r.period_end', 'd.amount', 'r.journal_entry_id']);
        $soFar = $posted->reduce(fn (BigDecimal $t, $r) => $t->plus((string) $r->amount), BigDecimal::zero());
        $last = DB::table('depreciation_runs')->where('company_id', $a->company_id)->max('period_end');
        $month = self::monthEnd(max((string) $a->depreciation_starts_on, $last ? (new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d') : '0000-01-01'));
        $future = [];
        $running = $soFar;
        $impaired = (string) ($this->impaired((string) $a->company_id, [(string) $a->id])[$a->id] ?? '0');
        for ($i = 0; $i < $ahead && $a->status === 'active'; $i++) {
            $charge = $this->charge($a, (string) $running, $impaired, $posted->count() + $i);
            if (! $charge->isPositive()) {
                break;
            }
            $running = $running->plus($charge);
            $future[] = ['period_end' => $month, 'amount' => (string) $charge];
            $month = self::monthEnd((new DateTimeImmutable($month))->modify('+1 day')->format('Y-m-d'));
        }
        $depreciated = BigDecimal::of((string) $a->opening_depreciation)->plus($soFar)->plus($impaired)->toScale(2);

        return [
            'posted' => $posted->map(fn ($r) => ['period_end' => $r->period_end, 'amount' => (string) $r->amount, 'entry_id' => $r->journal_entry_id])->values()->all(),
            'impairments' => DB::table('asset_impairments')->where(['company_id' => $a->company_id, 'asset_id' => $a->id])->orderBy('impaired_on')
                ->get(['id', 'impaired_on', 'amount', 'reason', 'entry_id'])->map(fn ($r) => (array) $r)->all(),
            'impaired' => (string) BigDecimal::of($impaired)->toScale(2),
            'ahead' => $future,
            'depreciated' => (string) $depreciated,
            'book_value' => (string) BigDecimal::of((string) $a->cost)->minus($depreciated)->toScale(2),
        ];
    }
}
