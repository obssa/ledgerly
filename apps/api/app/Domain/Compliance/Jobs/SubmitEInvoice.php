<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Jobs;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\Models\EInvoiceSubmission;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sends one invoice to its tax authority. Survives outages: retries with growing backoff for ~2 days,
 * and a submission stays 'queued' or 'failed' (visible on the Home screen) until it clears.
 * Status changes follow the e-invoice state machine (config/state_machines.php).
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

    /**
     * Three steps, so no database transaction or connection is held while waiting for the tax authority:
     *   1. read the submission and build the payload      (short tenant transaction)
     *   2. call the authority                              (no transaction)
     *   3. store the outcome                               (short tenant transaction, row locked)
     * If step 3 fails after the authority accepted the invoice, the retry sends it again; connectors must
     * therefore be idempotent on the document id (authorities de-duplicate on the invoice number).
     */
    public function handle(ConnectorRegistry $registry, CurrentCompany $current): void
    {
        // One at a time per invoice: while it is being sent, it is not corrected or voided (EditPostedInvoice and
        // InvoiceWorkflow take the same lock), so what the authority registers is what the books say.
        $documentId = $this->inTenant($current, fn () => EInvoiceSubmission::query()->whereKey($this->submissionId)->value('document_id'));
        $lock = Cache::lock(self::lockKey((string) $documentId), 180);
        if (! $lock->get()) {
            $this->release(20);

            return;
        }
        try {
            $this->send($registry, $current);
        } finally {
            $lock->release();
        }
    }

    /**
     * Called before a posted invoice is corrected or voided: refused while it is being sent, and no sending starts
     * until this change is committed (or rolled back).
     */
    public static function holdFor(\App\Domain\Sales\Models\Document $document): void
    {
        $lock = Cache::lock(self::lockKey((string) $document->getKey()), 30);
        if (! $lock->get()) {
            throw new \App\Domain\Ledger\Exceptions\LedgerException("{$document->number} is being registered with the tax authority right now; try again in a minute.");
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $lock->release());
            DB::afterRollBack(fn () => $lock->release());
        } else {
            $lock->release();
        }
    }

    public static function lockKey(string $documentId): string
    {
        return "einvoice-in-flight:{$documentId}";
    }

    private function send(ConnectorRegistry $registry, CurrentCompany $current): void
    {
        $prepared = $this->inTenant($current, function (Company $company) use ($registry) {
            $submission = EInvoiceSubmission::query()->with('document')->findOrFail($this->submissionId);
            if ($submission->status === 'cleared') {
                return null; // idempotent
            }

            return [
                $registry->forAuthority($submission->authority) ?? throw new RuntimeException("No connector for {$submission->authority}"),
                EInvoicePayload::fromDocument($company, $submission->document),
                (array) ($company->settings['einvoicing'] ?? []), // stored encrypted in production (vault)
            ];
        });

        if ($prepared === null) {
            return;
        }
        [$connector, $payload, $credentials] = $prepared;

        $result = $connector->submit($payload, $credentials);

        $this->inTenant($current, function () use ($payload, $result) {
            $submission = EInvoiceSubmission::query()->lockForUpdate()->findOrFail($this->submissionId);
            if ($submission->status === 'cleared') {
                return;
            }

            $submission->attempts++;
            $submission->request_payload = $payload->toArray();
            $submission->submitted_at = now();
            $submission->response_payload = $result->raw;

            // Each outcome is a move the e-invoice state machine allows from queued, submitted or failed.
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
                'not_ready' => $submission->forceFill(['last_error' => $result->error]), // connector not live yet: stays queued
                default => $submission->forceFill(['status' => 'failed', 'last_error' => $result->error]),
            };
            $submission->save();
        });

        if ($result->status === 'retryable_error') {
            $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
        }
    }

    /**
     * Queued jobs run outside any request: open a transaction and set the tenant so row-level security and
     * model scopes apply. The context is local to that transaction and disappears when it ends.
     */
    private function inTenant(CurrentCompany $current, Closure $work): mixed
    {
        // Run inline inside a request for the same company (sync queue in tests): keep that request's context.
        if ($current->has() && $current->id() === $this->companyId && DB::transactionLevel() > 0) {
            return $work($current->get());
        }

        return DatabaseTenantContext::run(null, $this->companyId, function () use ($current, $work) {
            $current->set(Company::query()->findOrFail($this->companyId));

            try {
                return $work($current->get());
            } finally {
                $current->forget();
            }
        });
    }
}
