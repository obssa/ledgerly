<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organisation;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboarding step 2: create the company and install its country pack (chart of accounts, tax rates),
 * then make the signing-up user its owner.
 */
final class SetUpCompany
{
    /**
     * @param array{organisation_id?: string, legal_name: string, trading_name?: ?string, country_code: string,
     *              industry?: ?string, tin?: ?string, vat_registered?: bool, vat_number?: ?string,
     *              calendar?: string, fiscal_year_start_month?: int, base_currency?: string,
     *              conversion_date?: ?string} $data
     */
    public function handle(User $owner, array $data): Company
    {
        $pack = CountryPack::load($data['country_code']);
        $companyId = (string) Str::uuid();

        return DB::transaction(function () use ($owner, $data, $pack, $companyId) {
            // RLS only lets us write rows for the company in the session, so select it first.
            DatabaseTenantContext::set($owner->getKey(), $companyId);

            $organisationId = $data['organisation_id']
                ?? Organisation::query()->create(['name' => $data['legal_name']])->getKey();

            $company = new Company([
                'organisation_id' => $organisationId,
                'legal_name' => $data['legal_name'],
                'trading_name' => $data['trading_name'] ?? null,
                'country_code' => $pack->countryCode,
                'base_currency' => $data['base_currency'] ?? $pack->baseCurrency,
                'fiscal_year_start_month' => $data['fiscal_year_start_month'] ?? $pack->defaultFiscalYearStartMonth,
                'calendar' => $data['calendar'] ?? 'gregorian',
                'framework' => $pack->framework,
                'conversion_date' => $data['conversion_date'] ?? null,
                'industry' => $data['industry'] ?? null,
                'tin' => $data['tin'] ?? null,
                'vat_registered' => $data['vat_registered'] ?? false,
                'vat_number' => $data['vat_number'] ?? null,
            ]);
            $company->id = $companyId;
            $company->save();

            Membership::query()->create(['company_id' => $companyId, 'user_id' => $owner->getKey(), 'role' => 'owner']);

            $now = now();
            DB::table('accounts')->insert(array_map(fn (array $a) => [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => $a['code'],
                'name' => $a['name'],
                'type' => $a['type'],
                'subtype' => $a['subtype'] ?? null,
                'system_key' => $a['system_key'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $pack->accounts));

            DB::table('tax_rates')->insert(array_map(fn (array $r) => [
                'id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'country_code' => $pack->countryCode,
                'code' => $r['code'],
                'name' => $r['name'],
                'kind' => $r['kind'],
                'rate' => $r['rate'],
                'effective_from' => $r['effective_from'],
                'created_at' => $now,
            ], $pack->taxRates));

            DB::table('audit_log')->insert([
                'company_id' => $companyId,
                'user_id' => $owner->getKey(),
                'action' => 'created',
                'subject_type' => 'company',
                'subject_id' => $companyId,
                'after' => json_encode(['country' => $pack->countryCode, 'accounts' => count($pack->accounts)]),
            ]);

            return $company;
        });
    }
}
