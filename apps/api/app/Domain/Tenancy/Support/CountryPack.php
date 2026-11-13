<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Ledger\Currency;
use InvalidArgumentException;
use JsonException;

/**
 * A country pack is data, not code: chart of accounts, tax rates, calendars, languages and the e-invoicing
 * connector for one country. Adding a country = a new JSON file + one connector class.
 */
final readonly class CountryPack
{
    /** System accounts the posting code relies on; every pack must provide them. */
    public const REQUIRED_SYSTEM_KEYS = [
        'accounts_receivable', 'accounts_payable', 'vat_payable', 'vat_receivable', 'inventory', 'cogs',
        'sales', 'retained_earnings', 'opening_balance_equity', 'fx_gain_loss', 'bank_charges', 'undeposited_funds',
    ];

    /** Accounting frameworks and the chart template each one uses by default. */
    public const FRAMEWORKS = ['ifrs_sme'];

    private const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /**
     * @param list<array{code: string, name: string, type: string, subtype?: string, system_key?: string}> $accounts
     * @param list<array{code: string, name: string, kind: string, rate: string, effective_from: string}> $taxRates
     */
    public function __construct(
        public string $countryCode,
        public string $name,
        public string $baseCurrency,
        public string $framework,
        public string $chartTemplate,
        public array $calendars,
        public int $defaultFiscalYearStartMonth,
        public array $languages,
        public string $taxIdPattern,
        public array $accounts,
        public array $taxRates,
        public array $einvoicing,
        public array $paymentMethods,
    ) {
    }

    public static function load(string $countryCode, ?string $directory = null): self
    {
        $directory ??= dirname(__DIR__, 4) . '/database/country-packs';
        $code = strtoupper($countryCode);

        $pack = self::readJson("{$directory}/{$code}.json");
        $template = $pack['chart_template'] ?? 'ifrs_sme';
        $base = self::readJson("{$directory}/_chart_{$template}.json");

        $instance = new self(
            countryCode: $pack['country_code'],
            name: $pack['name'],
            baseCurrency: Currency::normalise($pack['base_currency']),
            framework: $pack['framework'] ?? 'ifrs_sme',
            chartTemplate: $template,
            calendars: $pack['calendars'],
            defaultFiscalYearStartMonth: (int) $pack['default_fiscal_year_start_month'],
            languages: $pack['languages'],
            taxIdPattern: $pack['tax_id_pattern'],
            accounts: array_merge($base['accounts'], $pack['extra_accounts'] ?? []),
            taxRates: $pack['tax_rates'],
            einvoicing: $pack['einvoicing'] ?? [],
            paymentMethods: $pack['payment_methods'] ?? [],
        );
        $instance->validate();

        return $instance;
    }

    /** @return list<string> */
    public static function available(?string $directory = null): array
    {
        $directory ??= dirname(__DIR__, 4) . '/database/country-packs';

        return array_values(array_map(
            fn (string $file) => basename($file, '.json'),
            array_filter(glob("{$directory}/*.json") ?: [], fn (string $f) => ! str_starts_with(basename($f), '_')),
        ));
    }

    public function isValidTaxId(string $taxId): bool
    {
        return preg_match('/' . $this->taxIdPattern . '/', $taxId) === 1;
    }

    private function validate(): void
    {
        if (! in_array($this->framework, self::FRAMEWORKS, true)) {
            throw new InvalidArgumentException("{$this->countryCode}: unknown accounting framework {$this->framework}");
        }

        $codes = [];
        $keys = [];

        foreach ($this->accounts as $account) {
            if (! in_array($account['type'], self::ACCOUNT_TYPES, true)) {
                throw new InvalidArgumentException("{$this->countryCode}: account {$account['code']} has invalid type {$account['type']}");
            }
            if (isset($codes[$account['code']])) {
                throw new InvalidArgumentException("{$this->countryCode}: duplicate account code {$account['code']}");
            }
            $codes[$account['code']] = true;

            if (isset($account['system_key'])) {
                if (isset($keys[$account['system_key']])) {
                    throw new InvalidArgumentException("{$this->countryCode}: duplicate system key {$account['system_key']}");
                }
                $keys[$account['system_key']] = true;
            }
        }

        $missing = array_diff(self::REQUIRED_SYSTEM_KEYS, array_keys($keys));
        if ($missing !== []) {
            throw new InvalidArgumentException("{$this->countryCode}: missing system accounts " . implode(', ', $missing));
        }

        foreach ($this->taxRates as $rate) {
            if (! is_numeric($rate['rate']) || $rate['rate'] < 0 || $rate['rate'] >= 1) {
                throw new InvalidArgumentException("{$this->countryCode}: tax rate {$rate['code']} must be between 0 and 1");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Country pack not found: {$path}");
        }

        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Invalid JSON in {$path}: {$e->getMessage()}", 0, $e);
        }
    }
}
