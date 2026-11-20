<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\InvoicePdf;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Module 2 (099): an invoice seen by the customer through a shared link (by WhatsApp, or pasted anywhere), without an
 * account: the invoice, what is still due, and its PDF. Nothing can be done through it. The token is checked by its
 * hash outside any company (invoice_link_lookup); only then is the invoice read, inside its own company's context,
 * and only that invoice; a voided invoice is not shown. Throttled like the other sign-in-free routes.
 */
final class InvoiceLinkController extends Controller
{
    private const GONE = 'This link is not valid any more: ask the company to send the invoice again.';

    public function show(Request $request): JsonResponse
    {
        return $this->withInvoice($request, fn (Company $company, Document $invoice, object $link) => response()->json([
            'company' => ['name' => (string) ($company->trading_name ?: $company->legal_name), 'tin' => $company->tin, 'email' => $company->email, 'phone' => $company->phone,
                'logo' => app(\App\Domain\Tenancy\CompanyLogo::class)->dataUri((string) $company->getKey()),
                'payment_details' => CompanySettingsController::template($company->settings['invoice_template'] ?? [])['payment_details'] ?? null],
            'customer' => $invoice->contact?->name,
            'invoice' => [
                'number' => $invoice->number, 'issue_date' => $invoice->issue_date->format('Y-m-d'), 'due_date' => $invoice->due_date?->format('Y-m-d'),
                'currency' => $invoice->currency, 'subtotal' => (string) $invoice->subtotal, 'tax_total' => (string) $invoice->tax_total, 'total' => (string) $invoice->total,
                'amount_due' => (string) $invoice->amount_due, 'paid' => $invoice->status === 'paid', 'notes' => $invoice->notes,
                'lines' => $invoice->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                    'amount' => bcadd((string) $l->line_subtotal, (string) $l->line_tax, 2)])->values(),
            ],
            'expires_at' => $link->expires_at,
        ]));
    }

    public function pdf(Request $request, InvoicePdf $pdf): Response|JsonResponse
    {
        return $this->withInvoice($request, fn (Company $company, Document $invoice) => response($pdf->render($company, $invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number) ?: 'invoice') . '.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /** @param callable(Company, Document, object): (Response|JsonResponse) $then */
    private function withInvoice(Request $request, callable $then): Response|JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/']])['token'];
        $link = DB::transaction(fn () => DB::selectOne('SELECT company_id, document_id, expires_at FROM invoice_link_lookup(?)', [hash('sha256', $token)]));
        if ($link === null) {
            return response()->json(['message' => self::GONE], 404);
        }

        return DatabaseTenantContext::run(null, $link->company_id, function () use ($link, $then) {
            $company = Company::query()->findOrFail($link->company_id);
            $invoice = Document::query()->where('type', 'invoice')->whereIn('status', ['posted', 'part_paid', 'paid'])
                ->with(['lines', 'contact:id,name,tax_id'])->find($link->document_id);   // as the PDF reads it
            if ($invoice === null) {
                return response()->json(['message' => self::GONE], 404);   // voided since it was shared
            }

            return $then($company, $invoice, $link);
        });
    }
}
