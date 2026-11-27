<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * The fields QuickBooks' Customize turns on (111): the document's class and location, its custom fields, and each
 * line's service date, class and location. Each dimension is one of the company's active ones of the right kind
 * (row-level security scopes the lookup; the database checks again). Whether the company tracks them is applied when
 * the document is saved (SaveInvoiceDraft).
 */
final class DocumentFieldRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $dimension = fn (string $kind) => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', $kind)->where('is_active', true)];

        return [
            'class_id' => $dimension('class'),
            'location_id' => $dimension('location'),
            'custom_fields' => ['nullable', 'array', 'max:3'],
            'custom_fields.*' => ['nullable', 'string', 'max:100'],
            'lines.*.service_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.class_id' => $dimension('class'),
            'lines.*.location_id' => $dimension('location'),
        ];
    }
}
