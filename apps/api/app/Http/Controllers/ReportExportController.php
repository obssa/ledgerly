<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 10: every exported report is recorded (who exported it and when). The web builds the file from the report the
 * API gave the same person; this keeps its record in the audit log: which report, for which period, how many rows.
 */
final class ReportExportController extends Controller
{
    public const REPORTS = ['profit-and-loss', 'balance-sheet', 'trial-balance', 'general-ledger', 'cash-flow', 'changes-in-equity',
        'profit-by-dimension', 'sales', 'purchases', 'ar-ageing', 'ap-ageing', 'audit-log', 'stock-movement', 'stock-ageing', 'custom',
        'transaction-list', 'invoice-list', 'open-invoices', 'bill-list', 'unpaid-bills', 'payments-received', 'payments-made'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $data = $request->validate([
            'report' => ['required', Rule::in(self::REPORTS)],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],   // pdf: printed or saved as PDF from the browser
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'options' => ['nullable', 'array:kind,group,account_id,action,user_id,warehouse_id,rows,columns,measure,account_type,project_id,location_id,class_id'],
            'options.*' => ['nullable', 'string', 'max:60'],
            'rows' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $this->audit->record($this->current->id(), 'report_exported', 'company', $this->current->id(), null, $data, userId: $request->user()->getKey());

        return response()->json(['recorded' => true, 'at' => now()->toIso8601String()], 201);
    }
}
