<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 9c: the tax depreciation schedule (061), beside the books. For a fiscal year, each asset's written-down value
 * for tax at the start, the depreciation tax allows in the year and the value at the end, by the asset's tax rule
 * (or its book rule when it has none), month by month from the same start, to nothing; next to the book value and the
 * depreciation posted. The difference is what the tax return and deferred tax work from. Nothing posts.
 */
final class TaxDepreciation
{
    public function __construct(private readonly FixedAssets $assets)
    {
    }

    /**
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function year(Company $company, string $yearEnd): array
    {
        $end = new DateTimeImmutable($yearEnd);
        $from = $end->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        $assets = DB::table('fixed_assets')->where('company_id', $company->getKey())->where('acquired_on', '<=', $yearEnd)
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('disposed_on', '>=', $from))->orderBy('code')->get();
        $posted = DB::table('asset_depreciation as d')->join('depreciation_runs as r', 'r.id', '=', 'd.run_id')
            ->where('d.company_id', $company->getKey())->where('r.period_end', '<=', $yearEnd)
            ->get(['d.asset_id', 'r.period_end', 'd.amount'])->groupBy('asset_id');

        $zero = BigDecimal::zero()->toScale(2);
        $totals = array_fill_keys(['tax_opening', 'tax_additions', 'tax_depreciation', 'tax_closing', 'book_opening', 'book_depreciation', 'book_closing', 'difference'], $zero);
        $rows = [];
        foreach ($assets as $a) {
            $cost = BigDecimal::of((string) $a->cost);
            $disposed = $a->status === 'disposed' && $a->disposed_on !== null && $a->disposed_on <= $yearEnd ? (string) $a->disposed_on : null;

            // Tax: by its own rule, or the book rule when it has none.
            $rule = $a->tax_method === null
                ? (object) ['cost' => $a->cost, 'residual_value' => $a->residual_value, 'opening_depreciation' => $a->opening_depreciation, 'method' => $a->method,
                    'useful_life_months' => $a->useful_life_months, 'annual_rate' => $a->annual_rate]
                : (object) ['cost' => $a->cost, 'residual_value' => '0', 'opening_depreciation' => $a->tax_opening_depreciation, 'method' => $a->tax_method,
                    'useful_life_months' => $a->tax_useful_life_months, 'annual_rate' => $a->tax_annual_rate];
            $before = BigDecimal::zero();
            $within = BigDecimal::zero();
            $month = FixedAssets::monthEnd((string) $a->depreciation_starts_on);
            $last = $disposed !== null ? (new DateTimeImmutable($disposed))->modify('first day of this month')->modify('-1 day')->format('Y-m-d') : $yearEnd;
            while ($month <= $last && $month <= $yearEnd) {
                $charge = $this->assets->charge($rule, (string) $before->plus($within));
                if (! $charge->isPositive()) {
                    break;
                }
                $month < $from ? $before = $before->plus($charge) : $within = $within->plus($charge);
                $month = FixedAssets::monthEnd((new DateTimeImmutable($month))->modify('+1 day')->format('Y-m-d'));
            }
            $taxOpening = $cost->minus((string) $rule->opening_depreciation)->minus($before)->toScale(2);
            $taxClosing = $disposed !== null ? $zero : $taxOpening->minus($within)->toScale(2);

            // Books: what the runs posted.
            $bookBefore = BigDecimal::zero();
            $bookWithin = BigDecimal::zero();
            foreach ($posted[$a->id] ?? [] as $p) {
                $p->period_end < $from ? $bookBefore = $bookBefore->plus((string) $p->amount) : $bookWithin = $bookWithin->plus((string) $p->amount);
            }
            $bookOpening = $cost->minus((string) $a->opening_depreciation)->minus($bookBefore)->toScale(2);
            $bookClosing = $disposed !== null ? $zero : $bookOpening->minus($bookWithin)->toScale(2);
            $acquiredInYear = (string) $a->acquired_on >= $from;

            $row = [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'acquired_on' => $a->acquired_on, 'cost' => (string) $cost->toScale(2),
                'disposed_on' => $disposed,
                'tax_rule' => ['own' => $a->tax_method !== null, 'method' => $rule->method,
                    'useful_life_months' => $rule->useful_life_months === null ? null : (int) $rule->useful_life_months,
                    'annual_rate' => $rule->annual_rate === null ? null : (string) $rule->annual_rate],
                // An asset bought in the year starts the year with nothing to write down.
                'tax_opening' => (string) ($acquiredInYear ? $zero : $taxOpening), 'tax_additions' => (string) ($acquiredInYear ? $taxOpening : $zero),
                'tax_depreciation' => (string) $within->toScale(2), 'tax_closing' => (string) $taxClosing,
                'book_opening' => (string) ($acquiredInYear ? $zero : $bookOpening), 'book_depreciation' => (string) $bookWithin->toScale(2), 'book_closing' => (string) $bookClosing,
                'difference' => (string) $taxClosing->minus($bookClosing)->toScale(2),
            ];
            foreach (['tax_opening', 'tax_additions', 'tax_depreciation', 'tax_closing', 'book_opening', 'book_depreciation', 'book_closing', 'difference'] as $k) {
                $totals[$k] = $totals[$k]->plus($row[$k]);
            }
            $rows[] = $row;
        }

        return ['from' => $from, 'to' => $yearEnd, 'rows' => $rows, 'totals' => array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals)];
    }
}
