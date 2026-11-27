<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Models\PaymentTerm;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * QuickBooks' Terms list (109): every member reads it (the invoice and bill forms offer it); owners and admins add,
 * change and turn off terms (company.manage). A term is never deleted, so the documents that used it still read it.
 */
final class PaymentTermController extends Controller
{
    private const FIELDS = ['name', 'kind', 'days', 'day_of_month', 'next_month_within', 'is_active'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $all = $request->boolean('include_inactive');

        return response()->json(['data' => PaymentTerm::query()->when(! $all, fn ($q) => $q->where('is_active', true))
            ->orderByRaw("kind = 'day_of_month'")->orderByRaw('coalesce(days, day_of_month)')->orderBy('name')   // days terms first, shortest first
            ->get(['id', ...self::FIELDS])]);
    }

    public function store(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $data = $this->validated($request, null);
        $term = PaymentTerm::query()->create($data);
        $this->audit->record($current->id(), 'created', 'payment_term', $term->getKey(), null, $term->only(self::FIELDS), userId: $request->user()->getKey());

        return response()->json($term->only(['id', ...self::FIELDS]), 201);
    }

    public function update(Request $request, PaymentTerm $paymentTerm, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $data = $this->validated($request, $paymentTerm);
        $before = $paymentTerm->only(self::FIELDS);
        $paymentTerm->fill($data);
        $changes = AuditLogger::diff($before, $paymentTerm->only(self::FIELDS));
        if ($changes !== []) {
            $paymentTerm->save();
            $this->audit->record($current->id(), 'updated', 'payment_term', $paymentTerm->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes), userId: $request->user()->getKey());
        }

        return response()->json($paymentTerm->refresh()->only(['id', ...self::FIELDS]));
    }

    /** @return array<string, mixed> a whole term: days, or a day of the month and how near it a document goes to the next */
    private function validated(Request $request, ?PaymentTerm $term): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(['days', 'day_of_month'])],
            'days' => ['exclude_unless:kind,days', 'required', 'integer', 'between:0,365'],
            'day_of_month' => ['exclude_unless:kind,day_of_month', 'required', 'integer', 'between:1,31'],
            'next_month_within' => ['exclude_unless:kind,day_of_month', 'nullable', 'integer', 'between:0,31'],
            'is_active' => [$term ? 'sometimes' : 'prohibited', 'boolean'],
        ], [
            'day_of_month.between' => 'Choose a day from 1 to 31 (31 is the last day of any month).',
        ]);
        $data['name'] = trim($data['name']);
        $taken = PaymentTerm::query()->whereRaw('lower(name) = lower(?)', [$data['name']])
            ->when($term, fn ($q, PaymentTerm $t) => $q->whereKeyNot($t->getKey()))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => ["There is already a term called {$data['name']}."]]);
        }

        return $data['kind'] === 'days'
            ? [...$data, 'day_of_month' => null, 'next_month_within' => 0]
            : [...$data, 'days' => null, 'next_month_within' => (int) ($data['next_month_within'] ?? 0)];
    }
}
