<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Currency;
use App\Domain\Tenancy\Support\CountryPack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CountryPackTest extends TestCase
{
    public function test_every_shipped_pack_loads_and_has_the_system_accounts(): void
    {
        $codes = CountryPack::available();
        $this->assertTrue(in_array('ET', $codes, true) && in_array('KE', $codes, true));

        foreach ([...$codes, 'BR'] as $code) {
            $pack = CountryPack::load($code);
            $keys = array_column($pack->accounts, 'system_key');
            foreach (CountryPack::REQUIRED_SYSTEM_KEYS as $required) {
                $this->assertTrue(in_array($required, $keys, true), "{$code} is missing {$required}");
            }
            $this->assertSame(['en'], $pack->languages, "{$code}: the product is English only");
        }
    }

    public function test_ethiopia_pack_is_the_launch_pack(): void
    {
        $pack = CountryPack::load('ET');

        $this->assertTrue($pack->localised);
        $this->assertSame('ETB', $pack->baseCurrency);
        $this->assertSame('Africa/Addis_Ababa', $pack->timezone);
        $this->assertTrue(in_array('ethiopian', $pack->calendars, true));
        $this->assertSame(11, $pack->fiscalYear['ethiopian_start_month']);  // Hamle
        $this->assertSame('ET_MOR', $pack->einvoicing['authority']);
        $this->assertTrue($pack->isValidTaxId('0012345678'));
        $this->assertFalse($pack->isValidTaxId('12345'));

        $rates = array_column($pack->taxRates, 'rate', 'code');
        $this->assertSame('0.15', $rates['VAT15']);
        $this->assertSame('0.03', $rates['WHT3']);   // Proclamation 1395/2025: 3% (was 2%)
        $this->assertSame('0.30', $rates['WHT30']);  // supplier without a TIN
        // Turnover tax was repealed by Proclamation 1395/2025: no TOT codes, no TOT account.
        $this->assertSame([], array_filter(array_keys($rates), fn (string $c) => str_starts_with($c, 'TOT')));
        $this->assertFalse(in_array('tot_payable', array_column($pack->accounts, 'system_key'), true));

        $this->assertSame('20000.00', $pack->rules['withholding']['goods_above']);
        $this->assertSame('10000.00', $pack->rules['withholding']['services_above']);
        $this->assertSame('50000.00', $pack->rules['cash_payment_limit']);
    }

    public function test_ifrs_countries_share_the_ifrs_chart(): void
    {
        $this->assertSame('ifrs_sme', CountryPack::load('ET')->chartTemplate);
        $this->assertSame('ifrs_sme', CountryPack::load('KE')->chartTemplate);
    }

    public function test_kenya_pack(): void
    {
        $pack = CountryPack::load('KE');

        $this->assertSame('KES', $pack->baseCurrency);
        $this->assertSame('KE_KRA', $pack->einvoicing['authority']);
        $this->assertTrue($pack->isValidTaxId('P051234567Z'));
    }

    public function test_any_other_country_gets_the_default_pack(): void
    {
        foreach (['BR', 'IN', 'DE', 'us', 'AE', 'ZA'] as $code) {
            $pack = CountryPack::load($code);

            $this->assertFalse($pack->localised);
            $this->assertSame(strtoupper($code), $pack->countryCode);
            $this->assertSame([], $pack->einvoicing, 'no e-invoicing connector without a country pack');
            $this->assertSame(['NOTAX'], array_column($pack->taxRates, 'code'));
            $this->assertTrue($pack->isValidTaxId('12.345.678/0001-95'));
        }

        try {
            CountryPack::load('Ethiopia');
            $this->fail('expected a rejection of a non ISO code');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'ISO 3166-1'));
        }
    }

    public function test_industry_templates_extend_the_chart_without_clashes(): void
    {
        $industries = CountryPack::industries();
        $this->assertTrue(count($industries) >= 12);

        foreach (CountryPack::available() as $country) {
            $pack = CountryPack::load($country);
            foreach ($industries as $key => $industry) {
                $accounts = $pack->accountsFor($key);
                $codes = array_column($accounts, 'code');

                $this->assertSame(count($codes), count(array_unique($codes)), "{$country}/{$key}: duplicate account code");
                $this->assertSame(count($pack->accounts) + count($industry['accounts']), count($accounts));
                $sorted = $codes;
                sort($sorted);
                $this->assertSame($sorted, $codes, "{$country}/{$key}: chart is not in code order");
                foreach ($industry['accounts'] as $account) {
                    $this->assertTrue(in_array($account['type'], ['asset', 'liability', 'equity', 'income', 'expense'], true));
                }
            }
        }

        $restaurant = array_column(CountryPack::load('ET')->accountsFor('restaurant_hospitality'), 'name', 'code');
        $this->assertSame('Food sales', $restaurant['4020']);
        $this->assertSame(CountryPack::load('ET')->accounts, CountryPack::load('ET')->accountsFor(null));
    }

    public function test_every_currency_is_accepted_and_always_has_two_decimals(): void
    {
        foreach (['ETB', 'USD', 'EUR', 'BRL', 'INR', 'JPY', 'KWD', 'XOF', 'ZAR'] as $code) {
            $this->assertSame(2, Currency::decimals($code));
        }
        $this->assertTrue(count(Currency::all()) > 140);
        $this->assertSame(count(Currency::all()), count(array_unique(Currency::all())));
    }
}
