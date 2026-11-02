<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Connectors;

use App\Domain\Compliance\Contracts\EInvoiceConnector;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\SubmissionResult;

/**
 * Ethiopia Ministry of Revenue — Electronic Invoice Registration System.
 *
 * Directive No. 1142/2026 sets the legal framework (registration number + QR on every valid e-invoice,
 * accredited sales-registration systems). The technical specification and rollout schedule are still to
 * be published, and Ledgerly will need accreditation as an approved system.
 *
 * Until then submissions stay queued (retryable), so invoices are posted normally and are cleared the day
 * this connector is implemented, without re-keying.
 */
final class EthiopiaMorConnector implements EInvoiceConnector
{
    public function __construct(private readonly array $config)
    {
    }

    public function authority(): string
    {
        return 'ET_MOR';
    }

    public function submit(EInvoicePayload $payload, array $credentials): SubmissionResult
    {
        return SubmissionResult::retryable('Awaiting Ministry of Revenue technical specification; submission kept in queue.');
    }

    public function status(string $reference, array $credentials): SubmissionResult
    {
        return SubmissionResult::pending(['reference' => $reference]);
    }
}
