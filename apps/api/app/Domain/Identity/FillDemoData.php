<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Fill with demo data": the demo's sample books (customers, invoices, payments, suppliers, bills, stock, a bank
 * statement) put into the demo company, through the API as the demo's owner, as DemoSetup does at deploy. One
 * transaction: if any step fails, nothing is left half-filled. Queued, as it makes many requests.
 */
final class FillDemoData implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $companyId, public readonly string $ownerUserId)
    {
    }

    public function uniqueId(): string
    {
        return $this->companyId;
    }

    public function handle(DemoSetup $setup): void
    {
        DatabaseTenantContext::run($this->ownerUserId, $this->companyId, fn () => $setup->fill($this->companyId, $this->ownerUserId));
    }
}
