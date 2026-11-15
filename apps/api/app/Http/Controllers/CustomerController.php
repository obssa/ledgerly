<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Currency;
use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 2, customers: the contacts a company sells to. Sales staff read and add them; editing and archiving is for
 * accountants, bookkeepers, owners and admins (contacts.edit). Archived, never deleted; every change is audited.
 * The open balance is what customers owe on posted invoices, in the base currency at each invoice's own rate.
 */
final class CustomerController extends Controller
{
    private const FIELDS = ['name', 'tax_id', 'email', 'phone', 'website', 'currency', 'payment_terms_days', 'credit_limit',
        'tax_exempt', 'tax_exempt_reason', 'address', 'delivery_address', 'notes', 'is_active'];

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

        $customers = Contact::query()->where('is_customer', true)
            ->when(! ($q['include_archived'] ?? false), fn ($b) => $b->where('is_active', true))
            // A bound value with the query builder's ilike; the search text never becomes SQL (CLAUDE.md rule 1).
            ->when($q['search'] ?? null, fn ($b, $s) => $b->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . addcslashes($s, '%_\\') . '%')
                ->orWhere('tax_id', $s)))
            ->orderByRaw('lower(name)')->limit(500)
            ->get(['id', 'name', 'tax_id', 'email', 'phone', 'currency', 'payment_terms_days', 'credit_limit', 'is_active']);

        $balances = $this->balances($customers->pluck('id')->all());

        return response()->json([
            'data' => $customers->map(fn (Contact $c) => [...$c->toArray(), 'open_balance' => $balances[$c->getKey()] ?? '0.00']),
            'can' => $this->can(),
        ]);
    }

    public function show(Contact $customer): JsonResponse
    {
        $this->authorizeCompany('contacts.view');
        $this->assertCustomer($customer);

        return response()->json($this->present($customer));
    }

    public function store(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('contacts.create');
        $data = $this->validated($request, $current, creating: true);

        $this->assertExemptionHasReason((bool) ($data['tax_exempt'] ?? false), $data['tax_exempt_reason'] ?? null);
        $customer = Contact::query()->create([...$data, 'is_customer' => true]);
        $this->audit->record($current->id(), 'created', 'customer', $customer->getKey(), null, $data, userId: $request->user()->getKey());

        return response()->json($this->present($customer->refresh()), 201);
    }

    public function update(Request $request, Contact $customer, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('contacts.edit');
        $this->assertCustomer($customer);
        $data = $this->validated($request, $current, creating: false);

        $before = $customer->only(self::FIELDS);
        $customer->fill($data);
        if (! $customer->tax_exempt) {
            $customer->tax_exempt_reason = null;
        }
        $this->assertExemptionHasReason((bool) $customer->tax_exempt, $customer->tax_exempt_reason);
        $changes = AuditLogger::diff($before, $customer->only(self::FIELDS));
        if ($changes !== []) {
            $customer->save();
            $this->audit->record($current->id(), 'updated', 'customer', $customer->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes),
                userId: $request->user()->getKey());
        }

        return response()->json($this->present($customer->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CurrentCompany $current, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $address = fn (string $f) => [
            "{$f}" => ['sometimes', 'array:line1,line2,city,region,postal_code,country'],
            "{$f}.line1" => ['nullable', 'string', 'max:200'],
            "{$f}.line2" => ['nullable', 'string', 'max:200'],
            "{$f}.city" => ['nullable', 'string', 'max:100'],
            "{$f}.region" => ['nullable', 'string', 'max:100'],
            "{$f}.postal_code" => ['nullable', 'string', 'max:20'],
            "{$f}.country" => ['nullable', 'string', 'size:2', 'alpha'],
        ];

        $data = $request->validate([
            'name' => [$req, 'string', 'max:200'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
            'website' => ['sometimes', 'nullable', 'url', 'max:200'],
            'currency' => ['sometimes', 'nullable', Rule::in(Currency::all())],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,365'],
            'credit_limit' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:999999999999999999'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'tax_exempt_reason' => ['sometimes', 'nullable', 'string', 'max:300'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            ...$address('address'),
            ...$address('delivery_address'),
        ]);

        if (! empty($data['tax_id'])) {
            $pack = CountryPack::load($current->get()->country_code);
            if (! $pack->isValidTaxId($data['tax_id'])) {
                throw ValidationException::withMessages(['tax_id' => ["The {$pack->taxIdLabel} format is not valid for this country."]]);
            }
        }
        foreach (['address', 'delivery_address'] as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = array_filter($data[$f] ?? [], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Contact $customer): array
    {
        $invoices = DB::table('documents')
            ->where(['contact_id' => $customer->getKey(), 'type' => 'invoice'])
            ->whereIn('status', ['posted', 'part_paid', 'paid'])
            ->orderByDesc('issue_date')->limit(20)
            ->get(['id', 'number', 'issue_date', 'due_date', 'currency', 'total', 'amount_due', 'status']);

        return [
            ...$customer->only(['id', ...self::FIELDS]),
            'open_balance' => $this->balances([$customer->getKey()])[$customer->getKey()] ?? '0.00',
            'recent_invoices' => $invoices,
            'can' => $this->can(),
        ];
    }

    /** @param list<string> $ids @return array<string, string> open balance per customer, base currency */
    private function balances(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('documents')
            ->whereIn('contact_id', $ids)->where('type', 'invoice')->whereIn('status', ['posted', 'part_paid'])
            ->groupBy('contact_id')
            ->selectRaw('contact_id, sum(round(amount_due * fx_rate, 2))::numeric(20,2)::text as balance')
            ->pluck('balance', 'contact_id')->all();
    }

    /** What the signed-in member may do, so the screen offers only that; the API checks again on every call. */
    private function can(): array
    {
        $membership = app(CurrentCompany::class)->membership();

        return ['create' => (bool) $membership?->can('contacts.create'), 'edit' => (bool) $membership?->can('contacts.edit')];
    }

    /** The customer as it will be saved: an exemption always names its reason (also a database rule, 015). */
    private function assertExemptionHasReason(bool $exempt, ?string $reason): void
    {
        if ($exempt && blank($reason)) {
            throw ValidationException::withMessages(['tax_exempt_reason' => ['Say why the customer is exempt, for example the exemption certificate.']]);
        }
    }

    private function assertCustomer(Contact $contact): void
    {
        if (! $contact->is_customer) {
            throw new NotFoundHttpException();
        }
    }
}
