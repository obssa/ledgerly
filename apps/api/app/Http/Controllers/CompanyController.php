<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Tenancy\Actions\SetUpCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CountryPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CompanyController extends Controller
{
    /** Companies the user can switch to (company switcher). Row-level security limits this to active memberships. */
    public function index(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->whereIn('id', fn ($q) => $q->select('company_id')->from('memberships')
                ->where('user_id', $request->user()->getKey())->where('status', 'active'))
            ->orderBy('legal_name')
            ->get(['id', 'organisation_id', 'legal_name', 'trading_name', 'country_code', 'base_currency', 'status']);

        return response()->json(['data' => $companies]);
    }

    /** What the setup screen offers: countries with their own pack, industries, currencies. */
    public function options(): JsonResponse
    {
        return response()->json([
            'localised_countries' => CountryPack::available(),
            'industries' => array_map(fn (array $i) => $i['name'], CountryPack::industries()),
            'currencies' => Currency::all(),
            'calendars' => ['gregorian', 'ethiopian'],
        ]);
    }

    /**
     * Onboarding: create a company. Any country is accepted: one with a pack gets its taxes, rules and
     * e-invoicing; any other gets the default pack and must say which currency it keeps its books in.
     */
    public function store(Request $request, SetUpCompany $setUp): JsonResponse
    {
        // A company's books are opened only for a person whose email address is confirmed (SECURITY.md S3).
        if ($request->user()->email_verified_at === null) {
            return response()->json([
                'message' => 'Confirm your email address first: we sent you a link when you signed up.',
                'type' => 'EmailNotVerified',
            ], 403);
        }

        $data = $request->validate([
            // Another company of an existing organisation (multi-company); without it a new organisation starts.
            'organisation_id' => ['nullable', 'uuid'],
            'legal_name' => ['required', 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'legal_form' => ['nullable', Rule::in(CompanySettingsController::LEGAL_FORMS)],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'base_currency' => ['nullable', Rule::in(Currency::all())],
            'industry' => ['nullable', Rule::in(array_keys(CountryPack::industries()))],
            'tin' => ['nullable', 'string', 'max:30'],
            'vat_registered' => ['boolean'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'url', 'max:200'],
            'calendar' => ['nullable', Rule::in(['gregorian', 'ethiopian'])],
            'timezone' => ['nullable', 'timezone'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            // Last bank statement date before switching to Ledgerly: opening balances are entered as at this date.
            // The company's time zone is not set yet: no later than today anywhere (UTC+14).
            'conversion_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . now('Pacific/Kiritimati')->format('Y-m-d')],
        ]);

        // The database decides who may add a company to an organisation: its creator, or an owner or admin of one
        // of its companies (008_access_hardening.sql). Asked first, so the answer is a clear 403, not a failed insert.
        if (! empty($data['organisation_id'])
            && ! DB::selectOne('select app_can_add_company(?::uuid) as ok', [$data['organisation_id']])->ok) {
            return response()->json(['message' => 'Only an owner or admin of this organisation can add a company to it.', 'type' => 'NotOrganisationAdmin'], 403);
        }

        $pack = CountryPack::load($data['country_code']);

        if (! $pack->localised && empty($data['base_currency'])) {
            return response()->json(['message' => 'Choose the currency this company keeps its books in.', 'errors' => ['base_currency' => ['Required for this country']]], 422);
        }
        if (($data['calendar'] ?? 'gregorian') !== 'gregorian' && ! in_array($data['calendar'], $pack->calendars, true)) {
            return response()->json(['message' => 'This calendar is not offered for this country.', 'errors' => ['calendar' => ['Not available']]], 422);
        }
        if (! empty($data['tin']) && ! $pack->isValidTaxId($data['tin'])) {
            return response()->json(['message' => "The {$pack->taxIdLabel} format is not valid for this country.", 'errors' => ['tin' => ['Invalid format']]], 422);
        }

        return response()->json($setUp->handle($request->user(), $data), 201);
    }
}
