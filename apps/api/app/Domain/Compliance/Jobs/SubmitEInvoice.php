<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\Models\EInvoiceSubmission;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Sends one invoice to its tax authority. Survives outages: retries with growing backoff for ~2 days,
 * and a submission stays 'queued' or 'failed' (visible on the Home screen) until it clears.
 */
final class SubmitEInvoice implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 12;

    public function __construct(public readonly string $companyId, public readonly string $submissionId)
    {
        $this->onQueue('compliance');
    }

    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400];
    }

    public function handle(ConnectorRegistry $registry, CurrentCompany $current): void
    {
        // Queued jobs run outside any request: set the tenant so RLS and scopes apply. When the job runs
        // inline inside a request for the same company (sync queue), keep that request's context as is.
        $ownsContext = ! ($current->has() && $current->id() === $this->companyId);
        if ($ownsContext) {
            DatabaseTenantContext::set(null, $this->companyId);
            $current->set(Company::query()->findOrFail($this->companyId));
        }
        $company = $current->get();

        try {
            $submission = EInvoiceSubmission::query()->with('document')->findOrFail($this->submissionId);
            if ($submission->status === 'cleared') {
                return; // idempotent
            }

            $connector = $registry->forAuthority($submission->authority)
                ?? throw new RuntimeException("No connector for {$submission->authority}");

            $payload = EInvoicePayload::fromDocument($company, $submission->document);
            $credentials = (array) ($company->settings['einvoicing'] ?? []); // stored encrypted in production (vault)

            $submission->attempts++;
            $submission->request_payload = $payload->toArray();
            $submission->submitted_at = now();
            $result = $connector->submit($payload, $credentials);
            $submission->response_payload = $result->raw;

            match ($result->status) {
                'cleared' => $submission->forceFill([
                    'status' => 'cleared',
                    'clearance_number' => $result->clearanceNumber,
                    'qr_payload' => $result->qrPayload,
                    'signature' => $result->signature,
                    'cleared_at' => now(),
                    'last_error' => null,
                ]),
                'rejected' => $submission->forceFill(['status' => 'rejected', 'last_error' => $result->error]),
                'pending' => $submission->forceFill(['status' => 'submitted']),
                default => $submission->forceFill(['status' => 'failed', 'last_error' => $result->error]),
            };
            $submission->save();

            if ($result->status === 'retryable_error') {
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
            }
        } finally {
            if ($ownsContext) {
                DatabaseTenantContext::clear();
                $current->forget();
            }
        }
    }
}
