<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\SetUpCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CompanyController extends Controller
{
    /** Companies the user can switch to (company switcher). */
    public function index(Request $request): JsonResponse
    {
        DatabaseTenantContext::set($request->user()->getKey(), null);

        try {
            $companies = Company::query()
                ->whereIn('id', fn ($q) => $q->select('company_id')->from('memberships')->where('user_id', $request->user()->getKey()))
                ->orderBy('legal_name')
                ->get(['id', 'legal_name', 'trading_name', 'country_code', 'base_currency']);
        } finally {
            DatabaseTenantContext::clear();
        }

        return response()->json(['data' => $companies]);
    }

    /** Onboarding: create a company from a country pack. */
    public function store(Request $request, SetUpCompany $setUp): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'country_code' => ['required', Rule::in(CountryPack::available())],
            'industry' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'string', 'max:30'],
            'vat_registered' => ['boolean'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'calendar' => ['nullable', Rule::in(['gregorian', 'ethiopian'])],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            // Last bank statement date before switching to Ledgerly: opening balances are entered as at this date.
            'conversion_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        if (! empty($data['tin']) && ! CountryPack::load($data['country_code'])->isValidTaxId($data['tin'])) {
            return response()->json(['message' => 'The tax ID format is not valid for this country.', 'errors' => ['tin' => ['Invalid format']]], 422);
        }

        try {
            $company = $setUp->handle($request->user(), $data);
        } finally {
            DatabaseTenantContext::clear();
        }

        return response()->json($company, 201);
    }
}
