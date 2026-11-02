<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('is_customer', true)],
            'number' => ['required', 'string', 'max:40'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,8', 'gt:0'],
            'prices_include_tax' => ['boolean'],
            'language' => ['nullable', 'in:en,am,om,sw,fr'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'not_in:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,4'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')],
        ];
    }
}
