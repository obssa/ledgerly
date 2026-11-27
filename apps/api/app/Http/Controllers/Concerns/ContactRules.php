<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Validation\ValidationException;

/** What customers and suppliers share: an address shape and a tax ID checked against the company's country. */
trait ContactRules
{
    /** @return array<string, list<string>> rules for one address field */
    protected static function addressRules(string $field): array
    {
        return [
            $field => ['sometimes', 'array:line1,line2,city,region,postal_code,country'],
            "{$field}.line1" => ['nullable', 'string', 'max:200'],
            "{$field}.line2" => ['nullable', 'string', 'max:200'],
            "{$field}.city" => ['nullable', 'string', 'max:100'],
            "{$field}.region" => ['nullable', 'string', 'max:100'],
            "{$field}.postal_code" => ['nullable', 'string', 'max:20'],
            "{$field}.country" => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }

    /** Drops empty parts of the given object fields and checks the tax ID format for the company's country. */
    protected static function tidyContact(array $data, CurrentCompany $current, array $objectFields): array
    {
        if (! empty($data['tax_id'])) {
            $pack = CountryPack::load($current->get()->country_code);
            if (! $pack->isValidTaxId($data['tax_id'])) {
                throw ValidationException::withMessages(['tax_id' => ["The {$pack->taxIdLabel} format is not valid for this country."]]);
            }
        }
        foreach ($objectFields as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = array_filter($data[$f] ?? [], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $data;
    }

    /** @return array<string, list<mixed>> the usual term (109): one of the company's terms in use (row-level security scopes the lookup) */
    protected static function termsRules(): array
    {
        return ['payment_terms_id' => ['sometimes', 'nullable', 'uuid', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('is_active', true)]];
    }

    /**
     * The term and the days kept in step: a term gives its days (30 for a day of the month); days alone (an import, an
     * older client) take the company's term of those days when there is one.
     *
     * @param array<string, mixed> $data @return array<string, mixed>
     */
    protected static function withTerms(array $data, bool $creating): array
    {
        if (! empty($data['payment_terms_id'])) {
            $term = \App\Domain\Sales\Models\PaymentTerm::query()->find($data['payment_terms_id']);
            $data['payment_terms_days'] = $term?->kind === 'days' ? (int) $term->days : 30;
        } elseif (! array_key_exists('payment_terms_id', $data) && ($creating || array_key_exists('payment_terms_days', $data))) {
            $days = (int) ($data['payment_terms_days'] ?? 30);
            $data['payment_terms_id'] = \App\Domain\Sales\Models\PaymentTerm::query()->where(['kind' => 'days', 'days' => $days, 'is_active' => true])
                ->orderByRaw('length(name)')->value('id');
        }

        return $data;
    }
}
