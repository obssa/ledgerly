<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Money;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 3, supplier credits: what a supplier gives back on one posted bill (goods returned, a price corrected). Drafted
 * here, then approved like a bill (POST /bills/{id}/approve), which posts it (Dr payable, Cr cost and VAT receivable)
 * and applies it to the bill while that is still to pay. Credit left over can be applied to another open bill of the
 * same supplier.
 */
final class SupplierCreditController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return response()->json(Document::query()->where('type', 'vendor_credit')
            ->with(['contact:id,name', 'original:id,number,reference'])
            ->orderByDesc('issue_date')->orderBy('id')
            ->cursorPaginate(50));
    }

    /** A draft supplier credit for a posted bill: its lines (what was returned or taken off) and the supplier's reference. */
    public function store(Request $request, Document $bill, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        if ($bill->type !== 'bill') {
            throw new NotFoundHttpException();
        }
        if (! in_array($bill->status, ['posted', 'part_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['bill' => ["{$bill->label()} is not posted: change the draft instead."]]);
        }

        $data = $request->validate([
            'issue_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $bill->issue_date->format('Y-m-d')],
            'reference' => ['nullable', 'string', 'max:60'],   // the supplier's credit note number
            'notes' => ['required', 'string', 'min:3', 'max:2000'],   // the reason
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            // A bill's stock lines are on the inventory account: their credit goes back to it (goods returned) or to
            // inventory adjustment (a price correction on goods kept), so the inventory account still equals the stock (059).
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('is_active', true)
                ->where(fn (Builder $w) => $w->where('type', 'expense')->orWhere('system_key', 'inventory')
                    ->orWhere(fn (Builder $a) => $a->where('type', 'asset')->whereIn('subtype', ['fixed_asset', 'prepayment']))))],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')],   // the company's own (row-level security)
            'lines.*.batch_number' => ['nullable', 'string', 'max:2000'],   // 074: the batch going back, chosen by hand (the first to expire otherwise)
            'restock' => ['sometimes', 'boolean'],   // the goods went back to the supplier (059)
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->whereIn('kind', ['vat', 'tot'])],
            'lines.*.project_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'project')->where('is_active', true)],
        ], ['notes.required' => 'Say why: goods returned, a price corrected.']);

        $credit = $save->handle([...$data, 'prices_include_tax' => (bool) $bill->prices_include_tax], null, $request->user()->getKey(), $bill);

        return response()->json($credit->load('lines'), 201);
    }

    /** Applies credit left on a posted supplier credit to another open bill of the same supplier and currency. */
    public function apply(Request $request, Document $supplierCredit, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $credit = $supplierCredit;
        $data = $request->validate([
            'bill_id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
        ]);

        return DB::transaction(function () use ($data, $credit, $current, $request) {
            $target = Document::query()->where('type', 'bill')->find($data['bill_id'])
                ?? throw ValidationException::withMessages(['bill_id' => ['Not a bill of this company.']]);
            $docs = Document::query()->whereIn('id', [$credit->getKey(), $target->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $vc = $docs[$credit->getKey()];
            $doc = $docs[$target->getKey()];
            $amount = Money::of($data['amount'], $vc->currency);

            if (! in_array($vc->status, ['posted', 'part_paid'], true)) {
                throw ValidationException::withMessages(['amount' => ['This supplier credit has no credit left to apply.']]);
            }
            if ($doc->contact_id !== $vc->contact_id || $doc->currency !== $vc->currency || ! in_array($doc->status, ['posted', 'part_paid'], true)) {
                throw ValidationException::withMessages(['bill_id' => ['Choose an open bill of the same supplier, in the same currency.']]);
            }
            if (Money::of((string) $vc->amount_due, $vc->currency)->minus($amount)->isNegative() || Money::of((string) $doc->amount_due, $vc->currency)->minus($amount)->isNegative()) {
                throw ValidationException::withMessages(['amount' => ["At most {$vc->amount_due} of credit is left, and {$doc->number} has {$doc->amount_due} to pay."]]);
            }

            DB::table('credit_applications')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $current->id(), 'credit_note_id' => $vc->getKey(),
                'invoice_id' => $doc->getKey(), 'amount' => $amount->toDecimalString(), 'created_by' => $request->user()->getKey(),
            ]);
            $this->audit->record($current->id(), 'credit_applied', 'vendor_credit', $vc->getKey(), null,
                ['bill' => $doc->number, 'amount' => $amount->toDecimalString()], userId: $request->user()->getKey());

            return response()->json(['credit' => $vc->fresh(), 'bill' => $doc->fresh()]);
        });
    }
}
