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
            // 112: who it goes to and where, how it ships, and the customer's statement message.
            'billing_address' => ['nullable', 'string', 'max:500'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'email_to' => ['nullable', 'string', 'max:500', self::emails()],
            'email_cc' => ['nullable', 'string', 'max:500', self::emails()],
            'email_bcc' => ['nullable', 'string', 'max:500', self::emails()],
            'ship_via' => ['nullable', 'string', 'max:60'],
            'ship_date' => ['nullable', 'date_format:Y-m-d'],
            'tracking_number' => ['nullable', 'string', 'max:60'],
            'message_on_statement' => ['nullable', 'string', 'max:500'],
            // 113: QuickBooks' discount (before tax) and deposit, on sales documents.
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'required_with:discount_type', 'decimal:0,6', 'min:0', 'max:999999999999999999'],
            'deposit_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999999999'],
            'deposit_account_id' => ['nullable', 'uuid'],   // checked when saved: a money account in the invoice's currency
        ];
    }

    /** Addresses separated by commas or semicolons, each a valid email, ten at most. */
    public static function emails(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $list = self::split((string) $value);
            if (count($list) > 10) {
                $fail('Ten addresses at most.');
            }
            foreach ($list as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fail("{$email} is not an email address.");
                }
            }
        };
    }

    /** @return list<string> */
    public static function split(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) $value) ?: []), fn (string $e) => $e !== ''));
    }
}
