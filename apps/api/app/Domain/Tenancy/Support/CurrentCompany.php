<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use LogicException;

/**
 * The company the current request works in. Bound as a scoped singleton (reset per request / job),
 * set by the ResolveCompany middleware after the membership check.
 */
final class CurrentCompany
{
    private ?Company $company = null;

    private ?Membership $membership = null;

    public function set(Company $company, ?Membership $membership = null): void
    {
        $this->company = $company;
        $this->membership = $membership;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function get(): Company
    {
        return $this->company ?? throw new LogicException('No company selected for this request.');
    }

    public function id(): string
    {
        return $this->get()->getKey();
    }

    /**
     * Today's date where the company is (its time zone), as Y-m-d. "Not after today" checks and default dates use it:
     * the server runs in UTC, and in Addis Ababa (UTC+3) the day starts three hours earlier.
     */
    public function today(): string
    {
        return now($this->get()->timezone ?: 'UTC')->format('Y-m-d');
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function forget(): void
    {
        $this->company = null;
        $this->membership = null;
    }
}
