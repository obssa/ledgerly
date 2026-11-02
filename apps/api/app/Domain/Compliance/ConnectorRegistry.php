<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Compliance\Connectors\EthiopiaMorConnector;
use App\Domain\Compliance\Connectors\KenyaEtimsConnector;
use App\Domain\Compliance\Connectors\SandboxConnector;
use App\Domain\Compliance\Contracts\EInvoiceConnector;

/** Picks the e-invoicing connector for a company's country (config/einvoicing.php). */
final class ConnectorRegistry
{
    public function __construct(private readonly array $config)
    {
    }

    public function forCountry(string $countryCode): ?EInvoiceConnector
    {
        if ($this->config['force_sandbox'] ?? false) {
            return new SandboxConnector();
        }

        $country = $this->config['countries'][strtoupper($countryCode)] ?? null;

        return match ($country['connector'] ?? null) {
            'kenya_etims' => new KenyaEtimsConnector($country),
            'ethiopia_mor' => new EthiopiaMorConnector($country),
            'sandbox' => new SandboxConnector(),
            default => null, // country without e-invoicing (yet): invoices post without clearance
        };
    }

    public function forAuthority(string $authority): ?EInvoiceConnector
    {
        if ($authority === 'SANDBOX') {
            return new SandboxConnector();
        }

        foreach ($this->config['countries'] as $code => $country) {
            if (($country['authority'] ?? null) === $authority) {
                return $this->forCountry($code);
            }
        }

        return null;
    }
}
