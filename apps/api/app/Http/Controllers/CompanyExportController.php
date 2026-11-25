<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Export\CompanyExport;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Module 11: owners and admins download everything the company keeps, as a ZIP of CSV files (CompanyExport).
 * A few an hour per person, as it reads every table; every download is in the audit log.
 */
final class CompanyExportController extends Controller
{
    private const PER_HOUR = 3;

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit, private readonly CompanyExport $export)
    {
    }

    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorizeCompany('company.export');
        $companyId = $this->current->id();
        $key = 'company-export:' . $request->user()->getKey();
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return response()->json(['message' => 'The company data was downloaded several times this hour. Try again later.', 'type' => 'TooManyExports'], 429);
        }
        RateLimiter::hit($key, 3600);

        $path = tempnam(sys_get_temp_dir(), 'evenbooks-export-');
        $out = fopen($path, 'wb');
        try {
            $counts = $this->export->write($companyId, $out);
        } finally {
            fclose($out);
        }

        $this->audit->record($companyId, 'company_exported', 'company', $companyId, null,
            ['files' => count($counts), 'rows' => array_sum($counts)], userId: $request->user()->getKey());

        $name = Str::slug((string) ($this->current->get()->trading_name ?: $this->current->get()->legal_name)) ?: 'company';

        return response()->download($path, "evenbooks-{$name}-" . now()->format('Y-m-d') . '.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
