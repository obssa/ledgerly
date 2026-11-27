<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Currency;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 1, currencies: the foreign currencies a company works in (turned on and off by an owner or admin) and their
 * exchange rates, entered by an accountant. Rates are corrected, never deleted; every change is audited. A feed
 * from the central bank can write the same table later (source 'central_bank').
 */
final class CurrencyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly ExchangeRates $rates)
    {
    }

    /** The base currency, the foreign currencies and each one's latest rate. */
    public function index(CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $company = $current->get();

        $latest = DB::table('exchange_rates')->where('company_id', $company->getKey())
            ->select('currency', 'rate', 'rate_date')
            ->orderBy('currency')->orderByDesc('rate_date')
            ->get()->unique('currency')->keyBy('currency');

        // The accounts kept in each currency (a dollar bank account, a euro wallet), as QuickBooks lists them.
        $accounts = DB::table('accounts')->where('company_id', $company->getKey())->whereNotNull('currency')->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'currency'])->groupBy('currency');

        $currencies = DB::table('company_currencies')->where('company_id', $company->getKey())
            ->orderBy('currency')->get(['currency', 'is_enabled'])
            ->map(fn ($c) => [
                'currency' => $c->currency,
                'is_enabled' => (bool) $c->is_enabled,
                'latest_rate' => isset($latest[$c->currency]) ? (string) $latest[$c->currency]->rate : null,
                'latest_rate_date' => isset($latest[$c->currency]) ? (string) $latest[$c->currency]->rate_date : null,
                'accounts' => collect($accounts[$c->currency] ?? [])->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
            ]);

        return response()->json(['base_currency' => $company->base_currency, 'data' => $currencies->values(),
            // A rate older than this is not used for a new document (ExchangeRates::MAX_AGE_DAYS).
            'rate_max_age_days' => \App\Domain\Ledger\ExchangeRates::MAX_AGE_DAYS]);
    }

    /** Turns a foreign currency on or off. Off keeps its rates and documents; it only stops new documents using it. */
    public function update(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $company = $current->get();

        $data = $request->validate([
            'currency' => ['required', Rule::in(Currency::all()), Rule::notIn([$company->base_currency])],
            'enabled' => ['required', 'boolean'],
        ], ['currency.not_in' => 'This is the base currency of the company.']);

        $row = DB::table('company_currencies')->where(['company_id' => $company->getKey(), 'currency' => $data['currency']]);
        $before = $row->value('is_enabled');
        if ($before === null) {
            DB::table('company_currencies')->insert(['company_id' => $company->getKey(), 'currency' => $data['currency'], 'is_enabled' => $data['enabled']]);
        } elseif ((bool) $before !== $data['enabled']) {
            $row->update(['is_enabled' => $data['enabled'], 'updated_at' => now()]);
        }
        if ($before === null || (bool) $before !== $data['enabled']) {
            $this->audit->record($company->getKey(), $data['enabled'] ? 'currency_enabled' : 'currency_disabled', 'currency', null,
                $before === null ? null : ['currency' => $data['currency'], 'is_enabled' => (bool) $before],
                ['currency' => $data['currency'], 'is_enabled' => $data['enabled']], userId: $request->user()->getKey());
        }

        return response()->json(['currency' => $data['currency'], 'is_enabled' => $data['enabled']]);
    }

    /** Rates of one currency over a period, newest first (default: the last 90 days). */
    public function rates(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.view');

        $data = $request->validate([
            'currency' => ['required', Rule::in(Currency::all())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $data['to'] ?? app(CurrentCompany::class)->today();
        $from = $data['from'] ?? now()->subDays(90)->format('Y-m-d');

        return response()->json(['data' => DB::table('exchange_rates')
            ->where(['company_id' => $current->id(), 'currency' => $data['currency']])
            ->whereBetween('rate_date', [$from, $to])
            ->orderByDesc('rate_date')->limit(400)
            ->get(['id', 'currency', 'rate_date', 'rate', 'source'])]);
    }

    /** Records the rate of one day; a second entry for the same day corrects it, with the old value in the audit log. */
    public function storeRate(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('accounting.rates');
        $company = $current->get();

        $data = $request->validate([
            'currency' => ['required', Rule::in(Currency::all())],
            'rate_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'rate' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999'],
        ]);
        if (! $this->rates->isEnabled($company->getKey(), $data['currency'])) {
            throw ValidationException::withMessages(['currency' => ["Add {$data['currency']} to the company's currencies first."]]);
        }

        $key = ['company_id' => $company->getKey(), 'currency' => $data['currency'], 'rate_date' => $data['rate_date']];
        $existing = DB::table('exchange_rates')->where($key)->lockForUpdate()->first(['id', 'rate']);
        $userId = $request->user()->getKey();

        if ($existing === null) {
            $id = (string) Str::uuid7();
            DB::table('exchange_rates')->insert([...$key, 'id' => $id, 'rate' => $data['rate'], 'source' => 'manual', 'created_by' => $userId]);
            $this->audit->record($company->getKey(), 'rate_added', 'exchange_rate', $id, null,
                ['currency' => $data['currency'], 'rate_date' => $data['rate_date'], 'rate' => $data['rate']], userId: $userId);

            return response()->json(['id' => $id, ...$data], 201);
        }

        if (! BigDecimal::of((string) $existing->rate)->isEqualTo((string) $data['rate'])) {
            DB::table('exchange_rates')->where('id', $existing->id)->update(['rate' => $data['rate'], 'source' => 'manual', 'updated_at' => now()]);
            $this->audit->record($company->getKey(), 'rate_corrected', 'exchange_rate', $existing->id,
                ['rate' => (string) $existing->rate], ['rate' => $data['rate']], userId: $userId);
        }

        return response()->json(['id' => $existing->id, ...$data]);
    }
}
