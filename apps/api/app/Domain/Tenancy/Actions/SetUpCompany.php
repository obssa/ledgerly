<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Sales\PaymentTerms;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organisation;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use App\Models\User;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboarding: create the company, install its chart (base chart + country accounts + industry template)
 * and tax rates, open its first fiscal year and make the signing-up user its owner.
 *
 * Works for every country: one with a pack gets that country's taxes and rules; any other country gets
 * the default pack with the currency the company chose. Runs inside the request transaction.
 */
final class SetUpCompany
{
    /**
     * @param array{organisation_id?: string, legal_name: string, trading_name?: ?string, country_code: string,
     *              industry?: ?string, tin?: ?string, vat_registered?: bool, vat_number?: ?string,
     *              calendar?: string, date_format?: ?string, fiscal_year_start_month?: int, base_currency?: string, timezone?: string,
     *              phone?: ?string, email?: ?string, website?: ?string, conversion_date?: ?string, legal_form?: ?string,
     *              address_line1?: ?string, address_line2?: ?string, city?: ?string, region?: ?string, postal_code?: ?string} $data
     */
    public function handle(User $owner, array $data, ?string $companyId = null): Company
    {
        $pack = CountryPack::load($data['country_code']);
        $companyId ??= (string) Str::uuid7();   // a demo fresh start names the company first (101)
        $accounts = $pack->accountsFor($data['industry'] ?? null);
        $calendar = $data['calendar'] ?? 'gregorian';

        return DB::transaction(function () use ($owner, $data, $pack, $companyId, $accounts, $calendar) {
            // RLS only lets us write rows for the company in the transaction's context, so select it first.
            DatabaseTenantContext::set($owner->getKey(), $companyId);

            // An existing organisation must be one the user can already see (RLS); a new one starts its trial.
            $organisationId = isset($data['organisation_id'])
                ? Organisation::query()->findOrFail($data['organisation_id'])->getKey()
                : Organisation::query()->create([
                    'name' => $data['legal_name'],
                    'created_by' => $owner->getKey(),
                    'trial_ends_at' => now()->addDays(30),
                ])->getKey();

            $company = new Company([
                'organisation_id' => $organisationId,
                'legal_name' => $data['legal_name'],
                'trading_name' => $data['trading_name'] ?? null,
                'country_code' => $pack->countryCode,
                'base_currency' => $data['base_currency'] ?? $pack->baseCurrency,
                'fiscal_year_start_month' => $data['fiscal_year_start_month'] ?? $pack->defaultFiscalYearStartMonth,
                'calendar' => $calendar,
                'timezone' => $data['timezone'] ?? $pack->timezone,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'framework' => $pack->framework,
                'conversion_date' => $data['conversion_date'] ?? null,
                'industry' => $data['industry'] ?? null,
                'tin' => $data['tin'] ?? null,
                'vat_registered' => $data['vat_registered'] ?? false,
                'vat_number' => $data['vat_number'] ?? null,
                'legal_form' => $data['legal_form'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['region'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
            ]);
            // How dates are written (Settings, Date format): day first unless chosen otherwise.
            if (! empty($data['date_format'])) {
                $company->settings = ['date_format' => (string) $data['date_format']];
            }
            $company->id = $companyId;
            $company->save();

            Membership::query()->create(['company_id' => $companyId, 'user_id' => $owner->getKey(), 'role' => 'owner', 'status' => 'active']);

            // First fiscal year: the one containing the conversion date (or today).
            $fiscal = new FiscalCalendar($calendar, (int) $company->fiscal_year_start_month);
            $year = $fiscal->yearContaining(new DateTimeImmutable($data['conversion_date'] ?? 'today'));
            DB::table('fiscal_years')->insert([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'starts_on' => $year['start']->format('Y-m-d'),
                'ends_on' => $year['end']->format('Y-m-d'),
            ]);

            $now = now();
            DB::table('accounts')->insert(array_map(fn (array $a) => [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'code' => $a['code'],
                'name' => $a['name'],
                'type' => $a['type'],
                'subtype' => $a['subtype'] ?? null,
                'system_key' => $a['system_key'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $accounts));

            DB::table('tax_rates')->insert(array_map(fn (array $r) => [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'country_code' => $pack->countryCode,
                'code' => $r['code'],
                'name' => $r['name'],
                'kind' => $r['kind'],
                'rate' => $r['rate'],
                'effective_from' => $r['effective_from'],
                'created_at' => $now,
            ], $pack->taxRates));

            // QuickBooks' usual terms (109): Due on receipt, Net 15, 30 and 60, Due end of month.
            DB::table('payment_terms')->insert(array_map(fn (array $t) => [
                'id' => (string) Str::uuid7(), 'company_id' => $companyId, ...$t, 'created_at' => $now, 'updated_at' => $now,
            ], PaymentTerms::DEFAULTS));

            DB::table('audit_log')->insert([
                'company_id' => $companyId,
                'user_id' => $owner->getKey(),
                'action' => 'created',
                'subject_type' => 'company',
                'subject_id' => $companyId,
                'after' => json_encode(['country' => $pack->countryCode, 'industry' => $data['industry'] ?? null, 'accounts' => count($accounts)]),
            ]);

            return $company;
        });
    }
}
