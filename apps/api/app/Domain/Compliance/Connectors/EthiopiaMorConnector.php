<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Connectors;

use App\Domain\Compliance\Contracts\EInvoiceConnector;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\SubmissionResult;

/**
 * Ethiopia Ministry of Revenue — Electronic Invoice Registration System.
 *
 * Directive No. 1142/2026 sets the framework: every invoice is registered with the Ministry's central
 * system in real time BEFORE it is issued, and carries the Invoice Registration Number (IRN) and QR code
 * it gets back (receipts: a Receipt Registration Number). Invoicing software must be authorised by the
 * Ministry. The implementation schedule (Article 29) and the technical specification are not yet
 * published (checked 13 Nov 2026), so Ledgerly cannot be authorised yet.
 *
 * Until then submissions stay queued: invoices post to the books normally and are registered the day
 * this connector goes live, without re-keying. When it does, an invoice for a taxpayer in scope must not
 * be printed or sent before its IRN arrives; the document template already reserves the IRN and QR.
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
        return SubmissionResult::notReady('Awaiting the Ministry of Revenue implementation schedule and system authorisation; kept in queue.');
    }

    public function status(string $reference, array $credentials): SubmissionResult
    {
        return SubmissionResult::pending(['reference' => $reference]);
    }
}
