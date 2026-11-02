<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Connectors;

use App\Domain\Compliance\Contracts\EInvoiceConnector;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\SubmissionResult;

/**
 * Local development and tests: clears every invoice with a deterministic fake number,
 * rejects invoices whose buyer TIN is "REJECT" so the rejection UI can be built and tested.
 */
final class SandboxConnector implements EInvoiceConnector
{
    public function authority(): string
    {
        return 'SANDBOX';
    }

    public function submit(EInvoicePayload $payload, array $credentials): SubmissionResult
    {
        if ($payload->buyerTaxId === 'REJECT') {
            return SubmissionResult::rejected('Buyer TIN is not registered (sandbox)');
        }

        $number = 'SBX-' . strtoupper(substr(hash('sha256', $payload->documentId), 0, 12));

        return SubmissionResult::cleared(
            clearanceNumber: $number,
            qrPayload: "https://einvoice.sandbox.local/verify/{$number}",
            raw: ['echo' => $payload->number],
        );
    }

    public function status(string $reference, array $credentials): SubmissionResult
    {
        return SubmissionResult::cleared($reference);
    }
}
