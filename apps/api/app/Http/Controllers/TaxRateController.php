<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A company's tax rates. A country pack installs the statutory ones; a company in any other country
 * enters its own here (VAT, GST, sales tax, withholding). Rates are versioned, never edited: a change in
 * law is a new row with a new effective_from, so documents in earlier periods still recalculate correctly.
 */
final class TaxRateController extends Controller
{
    public function index(CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json(['data' => DB::table('tax_rates')
            ->where('company_id', $current->id())
            ->orderBy('code')->orderByDesc('effective_from')
            ->get(['id', 'code', 'name', 'kind', 'rate', 'effective_from', 'effective_to'])]);
    }

    public function store(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('tax.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['vat', 'sales_tax', 'tot', 'withholding', 'excise', 'other'])],
            'rate' => ['required', 'decimal:0,6', 'gte:0', 'lt:1'],   // 0.075 = 7.5%
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $company = $current->get();
        $id = (string) Str::uuid7();
        DB::table('tax_rates')->insert([...$data, 'id' => $id, 'company_id' => $company->getKey(), 'country_code' => $company->country_code]);

        return response()->json(['id' => $id, ...$data], 201);
    }
}
