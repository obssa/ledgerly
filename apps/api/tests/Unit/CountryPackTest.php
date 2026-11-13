<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tenancy\Support\CountryPack;
use PHPUnit\Framework\TestCase;

final class CountryPackTest extends TestCase
{
    public function test_every_shipped_pack_loads_and_has_the_system_accounts(): void
    {
        $codes = CountryPack::available();
        $this->assertTrue(in_array('ET', $codes, true) && in_array('KE', $codes, true));

        foreach ($codes as $code) {
            $pack = CountryPack::load($code);
            $keys = array_column($pack->accounts, 'system_key');
            foreach (CountryPack::REQUIRED_SYSTEM_KEYS as $required) {
                $this->assertTrue(in_array($required, $keys, true), "{$code} is missing {$required}");
            }
        }
    }

    public function test_ethiopia_pack(): void
    {
        $pack = CountryPack::load('ET');

        $this->assertSame('ETB', $pack->baseCurrency);
        $this->assertTrue(in_array('ethiopian', $pack->calendars, true));
        $this->assertSame('ET_MOR', $pack->einvoicing['authority']);
        $this->assertTrue($pack->isValidTaxId('0012345678'));
        $this->assertFalse($pack->isValidTaxId('12345'));
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
}
