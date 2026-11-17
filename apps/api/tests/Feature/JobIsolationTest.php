<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\ConnectorRegistry;
use App\Domain\Compliance\Jobs\SubmitEInvoice;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S13: a queued job works inside its own company only. It sets the tenant from the company id it was
 * queued with, in one transaction, so row-level security hides every other company's rows from it.
 */
final class JobIsolationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_job_for_company_a_cannot_touch_company_bs_rows(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $bSubmission = $this->owner()->table('einvoice_submissions')->where('company_id', $b['company'])->first();
        $this->assertNotNull($bSubmission, 'fixture: company B has an e-invoice submission');

        // As a queue worker runs it: no request, no company selected, no open transaction.
        $current = app(CurrentCompany::class);
        $current->forget();
        $this->assertSame(0, DB::transactionLevel());

        try {
            (new SubmitEInvoice($a['company'], $bSubmission->id))->handle(app(ConnectorRegistry::class), $current);
            $this->fail("a job for company A read company B's submission");
        } catch (ModelNotFoundException) {
            // hidden by row-level security
        }
        $this->assertFalse($current->has(), 'the job leaves no company selected behind');
        $this->assertEquals($bSubmission, $this->owner()->table('einvoice_submissions')->where('id', $bSubmission->id)->first(), "company B's submission is unchanged");

        // Control: the same job queued for company B finds it (so the refusal above is isolation, not a broken job).
        (new SubmitEInvoice($b['company'], $bSubmission->id))->handle(app(ConnectorRegistry::class), $current);
        $this->assertFalse($current->has());
    }
}
