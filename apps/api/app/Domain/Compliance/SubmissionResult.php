<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

final readonly class SubmissionResult
{
    private function __construct(
        public string $status, // cleared | rejected | pending | retryable_error
        public ?string $clearanceNumber = null,
        public ?string $qrPayload = null,
        public ?string $signature = null,
        public ?string $error = null,
        public array $raw = [],
    ) {
    }

    public static function cleared(string $clearanceNumber, ?string $qrPayload = null, ?string $signature = null, array $raw = []): self
    {
        return new self('cleared', $clearanceNumber, $qrPayload, $signature, null, $raw);
    }

    /** The authority refused the invoice (bad TIN, wrong tax code ...): a person must fix it. */
    public static function rejected(string $error, array $raw = []): self
    {
        return new self('rejected', error: $error, raw: $raw);
    }

    /** Accepted but not final yet: poll status() later. */
    public static function pending(array $raw = []): self
    {
        return new self('pending', raw: $raw);
    }

    /** Network down, authority unavailable, timeout: the job retries with backoff. */
    public static function retryable(string $error, array $raw = []): self
    {
        return new self('retryable_error', error: $error, raw: $raw);
    }
}
