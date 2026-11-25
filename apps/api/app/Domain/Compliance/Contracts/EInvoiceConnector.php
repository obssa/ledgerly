<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Contracts;

use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\SubmissionResult;

/**
 * One implementation per tax authority (MoR Ethiopia, KRA eTIMS, URA EFRIS, TRA VFD, RRA EBM, NRS MBS).
 * Connectors translate Evenbooks' normalised payload into the authority's format and back; they never
 * touch the ledger.
 */
interface EInvoiceConnector
{
    /** Short code stored on submissions, e.g. 'KE_KRA'. */
    public function authority(): string;

    /** Send an invoice or credit note for clearance/registration. Must be idempotent per document. */
    public function submit(EInvoicePayload $payload, array $credentials): SubmissionResult;

    /** Ask the authority for the current status of an earlier submission. */
    public function status(string $reference, array $credentials): SubmissionResult;
}
