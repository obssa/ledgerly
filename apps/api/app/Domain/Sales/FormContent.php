<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\Rule;

/**
 * What the invoice and bill forms show (QuickBooks' Customize: Sales form content, Purchase form content), kept in the
 * company's settings. Each field group is on or off for sales documents and for purchase documents; classes and
 * locations are tracked off, one per transaction, or one per line; up to three custom fields, each for sales, purchases
 * or both, printed or not. The API reads the same settings when it saves, so a field turned off is not stored.
 */
final class FormContent
{
    /** The field groups, and whether each starts on. */
    public const FIELDS = [
        'service_date' => false,          // a line's service date
        'sku' => false,                   // the item's SKU on each line
        'shipping' => false,              // ship to, ship via, shipping date, tracking no.
        'email_cc_bcc' => true,           // Cc and Bcc beside the customer's email
        'message_on_statement' => false,  // a note the customer's statement shows for the document
        'discount' => false,              // a discount on the whole document
        'deposit' => false,               // a deposit received with the invoice
        'tags' => true,
        'attachments' => true,
    ];

    public const TRACKING = ['off', 'transaction', 'line'];

    public const MAX_CUSTOM_FIELDS = 3;

    /**
     * @return array{sales: array<string, bool>, purchases: array<string, bool>, track_classes: string, track_locations: string,
     *               custom_fields: list<array{name: string, sales: bool, purchases: bool, print: bool}>}
     */
    public static function of(Company $company): array
    {
        $s = $company->settings['form_content'] ?? [];
        $side = fn (string $key) => array_map(fn ($d) => (bool) $d, [...self::FIELDS, ...array_intersect_key((array) ($s[$key] ?? []), self::FIELDS)]);

        return [
            'sales' => $side('sales'),
            'purchases' => $side('purchases'),
            'track_classes' => in_array($s['track_classes'] ?? null, self::TRACKING, true) ? $s['track_classes'] : 'off',
            'track_locations' => in_array($s['track_locations'] ?? null, self::TRACKING, true) ? $s['track_locations'] : 'off',
            'custom_fields' => array_values(array_map(fn (array $f) => [
                'name' => (string) $f['name'], 'sales' => (bool) ($f['sales'] ?? true), 'purchases' => (bool) ($f['purchases'] ?? false), 'print' => (bool) ($f['print'] ?? true),
            ], array_filter((array) ($s['custom_fields'] ?? []), fn ($f) => is_array($f) && isset($f['name'])))),
        ];
    }

    /** @return array<string, list<mixed>> the request rules for the form_content setting */
    public static function rules(): array
    {
        $rules = [
            'form_content' => ['sometimes', 'array:sales,purchases,track_classes,track_locations,custom_fields'],
            'form_content.sales' => ['sometimes', 'array:' . implode(',', array_keys(self::FIELDS))],
            'form_content.purchases' => ['sometimes', 'array:' . implode(',', array_keys(self::FIELDS))],
            'form_content.track_classes' => ['sometimes', Rule::in(self::TRACKING)],
            'form_content.track_locations' => ['sometimes', Rule::in(self::TRACKING)],
            'form_content.custom_fields' => ['sometimes', 'array', 'max:' . self::MAX_CUSTOM_FIELDS],
            'form_content.custom_fields.*.name' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
            'form_content.custom_fields.*.sales' => ['sometimes', 'boolean'],
            'form_content.custom_fields.*.purchases' => ['sometimes', 'boolean'],
            'form_content.custom_fields.*.print' => ['sometimes', 'boolean'],
        ];
        foreach (array_keys(self::FIELDS) as $f) {
            $rules["form_content.sales.{$f}"] = ['sometimes', 'boolean'];
            $rules["form_content.purchases.{$f}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** The setting with what was sent laid over what was kept. @param array<string, mixed> $given */
    public static function merge(Company $company, array $given): array
    {
        $now = self::of($company);
        foreach (['sales', 'purchases'] as $side) {
            if (isset($given[$side])) {
                $now[$side] = [...$now[$side], ...array_map(fn ($v) => (bool) $v, $given[$side])];
            }
        }
        foreach (['track_classes', 'track_locations'] as $k) {
            if (isset($given[$k])) {
                $now[$k] = (string) $given[$k];
            }
        }
        if (array_key_exists('custom_fields', $given)) {
            $now['custom_fields'] = array_values(array_map(fn (array $f) => ['name' => trim((string) $f['name']), 'sales' => (bool) ($f['sales'] ?? true),
                'purchases' => (bool) ($f['purchases'] ?? false), 'print' => (bool) ($f['print'] ?? true)], (array) $given['custom_fields']));
        }

        return $now;
    }

    /** Whether a kind of document is a purchase (bills, expenses, orders to suppliers, supplier credits). */
    public static function isPurchase(string $type): bool
    {
        return in_array($type, ['bill', 'expense', 'purchase_order', 'vendor_credit'], true);
    }
}
