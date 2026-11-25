<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\TransactionReport;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 10: the transaction reports (TransactionReport): Transaction list by date, Invoice list, Open invoices, Bill
 * list, Unpaid bills, Payments received and made. Reports, and the documents each lists: a role sees one only when it may
 * see those documents (the journal, invoices, bills or payments).
 */
final class TransactionReportController extends Controller
{
    /** What each report lists, and the permission that seeing those takes. */
    private const NEEDS = [
        'transactions' => 'accounting.view',
        'invoices' => 'invoices.view', 'open_invoices' => 'invoices.view',
        'bills' => 'bills.view', 'unpaid_bills' => 'bills.view',
        'payments_received' => 'payments.view', 'payments_made' => 'payments.view',
    ];

    public function __construct(private readonly CurrentCompany $current, private readonly TransactionReport $report)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'report' => ['required', Rule::in(array_keys(TransactionReport::REPORTS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'contact_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'draft', 'void'])],
            'created_by' => ['nullable', 'uuid'],
        ]);
        $this->authorizeCompany(self::NEEDS[$q['report']]);
        $company = $this->current->get();

        // Ids from the request are the company's own, or refused (CLAUDE.md, rule 4).
        if (! empty($q['contact_id']) && ! DB::table('contacts')->where(['company_id' => $company->getKey(), 'id' => $q['contact_id']])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['No such customer or supplier in this company.']]);
        }
        if (! empty($q['created_by']) && ! DB::table('memberships')->where(['company_id' => $company->getKey(), 'user_id' => $q['created_by']])->exists()) {
            throw ValidationException::withMessages(['created_by' => ['No such person in this company.']]);
        }
        // No dates: all dates (the web app sends the report period it shows).
        $from = $q['from'] ?? '1900-01-01';
        $to = $q['to'] ?? '2999-12-31';
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['A report ends on or after it starts.']]);
        }

        $result = $this->report->run((string) $company->getKey(), (string) $company->base_currency, $this->current->today(), $q['report'],
            ['from' => $from, 'to' => $to, 'contact_id' => $q['contact_id'] ?? null, 'status' => $q['status'] ?? null, 'created_by' => $q['created_by'] ?? null]);

        return response()->json(['report' => $q['report'], 'from' => $from, 'to' => $to, 'currency' => $company->base_currency, ...$result]);
    }
}
