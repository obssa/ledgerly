<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CountryPack;
use Illuminate\Support\Facades\DB;

/**
 * What the country pack says about withholding tax on a payment to this supplier: whether it applies, at which rate
 * (a lower one with a TIN, a higher one without), and above which amount. Advice for the person paying: the amount
 * withheld is theirs to confirm, and the API only refuses withholding from a supplier with an exemption certificate.
 */
final class WithholdingAdvice
{
    /** @return array{applies: bool, rate: ?string, code: ?string, goods_above: ?string, services_above: ?string, note: string} */
    public function for(Company $company, Contact $supplier): array
    {
        $rules = CountryPack::load($company->country_code)->rules['withholding'] ?? null;
        if ($rules === null) {
            return ['applies' => false, 'rate' => null, 'code' => null, 'goods_above' => null, 'services_above' => null,
                'note' => 'The country pack has no withholding rule for payments to suppliers.'];
        }
        if ($supplier->withholding_exempt) {
            return ['applies' => false, 'rate' => null, 'code' => null, 'goods_above' => null, 'services_above' => null,
                'note' => "Exempt: {$supplier->withholding_exempt_reason}"];
        }

        $code = blank($supplier->tax_id) ? ($rules['without_tin_code'] ?? $rules['default_code']) : $rules['default_code'];
        $rate = DB::table('tax_rates')->where(['company_id' => $company->getKey(), 'code' => $code, 'kind' => 'withholding'])
            ->where('effective_from', '<=', now($company->timezone ?: 'UTC')->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now($company->timezone ?: 'UTC')->format('Y-m-d')))
            ->orderByDesc('effective_from')->value('rate');

        return [
            'applies' => $rate !== null,
            'rate' => $rate === null ? null : (string) $rate,
            'code' => $code,
            'goods_above' => $rules['goods_above'] ?? null,
            'services_above' => $rules['services_above'] ?? null,
            'note' => (string) ($rules['note'] ?? ''),
        ];
    }

    /** The country's limit on one cash payment, in the base currency, if it has one. */
    public function cashLimit(Company $company): ?string
    {
        return CountryPack::load($company->country_code)->rules['cash_payment_limit'] ?? null;
    }
}
