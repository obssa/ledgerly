<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Currency;
use App\Domain\Ledger\Models\Account;
use App\Domain\Reports\ReceivablesReport;
use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Controllers\Concerns\ContactRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 3, suppliers: the contacts a company buys from (contacts.is_vendor). Purchasing and sales staff read and add
 * them; changing one, including where it is paid, is for accountants, bookkeepers, owners and admins (contacts.edit).
 * Archived, never deleted; every change is audited, payout details included (old and new values).
 * The open balance is what the company owes on posted bills, in the base currency at each bill's own rate.
 */
final class SupplierController extends Controller
{
    use ContactRules;

    private const FIELDS = ['name', 'tax_id', 'email', 'phone', 'website', 'currency', 'payment_terms_days', 'address', 'payout',
        'default_expense_account_id', 'withholding_exempt', 'withholding_exempt_reason', 'notes', 'is_active'];

    private const PAYOUT = ['bank_name', 'account_name', 'account_number', 'mobile_money_provider', 'mobile_money_number'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('contacts.view');
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $suppliers = Contact::query()->where('is_vendor', true)
            ->when(! ($q['include_archived'] ?? false), fn ($b) => $b->where('is_active', true))
            // A bound value with the query builder's ilike; the search text never becomes SQL (CLAUDE.md rule 1).
            ->when($q['search'] ?? null, fn ($b, $s) => $b->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . addcslashes($s, '%_\\') . '%')
                ->orWhere('tax_id', $s)))
            ->orderBy('name')->limit(500)
            ->get(['id', 'name', 'tax_id', 'email', 'phone', 'currency', 'payment_terms_days', 'default_expense_account_id', 'is_active']);

        $balances = $this->balances($suppliers->pluck('id')->all());

        return response()->json([
            'data' => $suppliers->map(fn (Contact $c) => [...$c->toArray(), 'open_balance' => $balances[$c->getKey()] ?? '0.00']),
            'can' => $this->can(),
        ]);
    }

    public function show(Contact $supplier): JsonResponse
    {
        $this->authorizeCompany('contacts.view');
        $this->assertSupplier($supplier);

        return response()->json($this->present($supplier));
    }

    /** The supplier's statement for a period (default: the last 90 days), from the payable account's lines. */
    public function statement(Request $request, Contact $supplier, CurrentCompany $current, ReceivablesReport $report): JsonResponse
    {
        $this->authorizeCompany('contacts.view');
        $this->assertSupplier($supplier);
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $q['to'] ?? app(CurrentCompany::class)->today();
        $from = $q['from'] ?? now()->subDays(90)->format('Y-m-d');
        $company = $current->get();

        return response()->json([
            'supplier' => $supplier->only(['id', 'name', 'tax_id', 'address']),
            ...$report->statement($company->getKey(), Account::system('accounts_payable')->getKey(), $supplier->getKey(), $company->base_currency, $from, $to, payable: true),
            'currency' => $company->base_currency,
        ]);
    }

    public function store(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('contacts.create');
        $data = $this->validated($request, $current, creating: true);

        $this->assertExemptionHasReason((bool) ($data['withholding_exempt'] ?? false), $data['withholding_exempt_reason'] ?? null);
        $supplier = Contact::query()->create([...$data, 'is_vendor' => true]);
        $this->audit->record($current->id(), 'created', 'supplier', $supplier->getKey(), null, $data, userId: $request->user()->getKey());

        return response()->json($this->present($supplier->refresh()), 201);
    }

    public function update(Request $request, Contact $supplier, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('contacts.edit');
        $this->assertSupplier($supplier);
        $data = $this->validated($request, $current, creating: false);

        $before = $supplier->only(self::FIELDS);
        $supplier->fill($data);
        if (! $supplier->withholding_exempt) {
            $supplier->withholding_exempt_reason = null;
        }
        $this->assertExemptionHasReason((bool) $supplier->withholding_exempt, $supplier->withholding_exempt_reason);
        $changes = AuditLogger::diff($before, $supplier->only(self::FIELDS));
        if ($changes !== []) {
            $supplier->save();
            $this->audit->record($current->id(), array_key_exists('payout', $changes) ? 'payout_changed' : 'updated', 'supplier', $supplier->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes),
                userId: $request->user()->getKey());
        }

        return response()->json($this->present($supplier->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CurrentCompany $current, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:200'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
            'website' => ['sometimes', 'nullable', 'url', 'max:200'],
            'currency' => ['sometimes', 'nullable', Rule::in(Currency::all())],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,365'],
            // Loaded through the company's own rows (row-level security); the database checks it again (019).
            'default_expense_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->whereIn('type', ['expense', 'asset'])],
            'withholding_exempt' => ['sometimes', 'boolean'],
            'withholding_exempt_reason' => ['sometimes', 'nullable', 'string', 'max:300'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'payout' => ['sometimes', 'array:' . implode(',', self::PAYOUT)],
            'payout.bank_name' => ['nullable', 'string', 'max:100'],
            'payout.account_name' => ['nullable', 'string', 'max:200'],
            'payout.account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z -]{4,40}$/'],
            'payout.mobile_money_provider' => ['nullable', 'string', 'max:40'],
            'payout.mobile_money_number' => ['nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
            ...self::addressRules('address'),
        ]);

        return self::tidyContact($data, $current, ['address', 'payout']);
    }

    /** @return array<string, mixed> */
    private function present(Contact $supplier): array
    {
        $bills = DB::table('documents')
            ->where(['contact_id' => $supplier->getKey(), 'type' => 'bill'])
            ->whereIn('status', ['posted', 'part_paid', 'paid'])
            ->orderByDesc('issue_date')->limit(20)
            ->get(['id', 'number', 'reference', 'issue_date', 'due_date', 'currency', 'total', 'amount_due', 'status']);

        return [
            ...$supplier->only(['id', 'is_customer', ...self::FIELDS]),
            'open_balance' => $this->balances([$supplier->getKey()])[$supplier->getKey()] ?? '0.00',
            'recent_bills' => $bills,
            'can' => $this->can(),
        ];
    }

    /** @param list<string> $ids @return array<string, string> open balance per supplier, base currency */
    private function balances(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('documents')
            ->whereIn('contact_id', $ids)->where('type', 'bill')->whereIn('status', ['posted', 'part_paid'])
            ->groupBy('contact_id')
            ->selectRaw('contact_id, sum(round(amount_due * fx_rate, 2))::numeric(20,2)::text as balance')
            ->pluck('balance', 'contact_id')->all();
    }

    /** What the signed-in member may do, so the screen offers only that; the API checks again on every call. */
    private function can(): array
    {
        $membership = app(CurrentCompany::class)->membership();

        return [
            'create' => (bool) $membership?->can('contacts.create'),
            'edit' => (bool) $membership?->can('contacts.edit'),
            'bill' => (bool) $membership?->can('bills.create'),
        ];
    }

    /** An exemption always names its certificate (also a database rule, 019). */
    private function assertExemptionHasReason(bool $exempt, ?string $reason): void
    {
        if ($exempt && blank($reason)) {
            throw ValidationException::withMessages(['withholding_exempt_reason' => ['Give the withholding exemption certificate number.']]);
        }
    }

    private function assertSupplier(Contact $contact): void
    {
        if (! $contact->is_vendor) {
            throw new NotFoundHttpException();
        }
    }
}
