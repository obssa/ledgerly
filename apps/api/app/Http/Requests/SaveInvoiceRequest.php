<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\Currency;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SaveInvoiceRequest extends FormRequest
{
    /**
     * Checked before the rules below run: a form request validates before the controller's first line, so
     * without this a role that may not save invoices got a validation answer (422) instead of a refusal
     * (SECURITY.md S4, found by PermissionMatrixTest). The controller checks again.
     */
    public function authorize(): bool
    {
        $membership = app(CurrentCompany::class)->membership();

        return $membership !== null && $membership->can($this->permission());
    }

    protected function failedAuthorization(): never
    {
        throw new HttpException(403, "Your role does not allow {$this->permission()}.");
    }

    /** Saving a draft needs invoices.create; changing a posted invoice needs transactions.edit. */
    private function permission(): string
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Document && $invoice->isPosted() ? 'transactions.edit' : 'invoices.create';
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('is_customer', true)],
            'number' => ['nullable', 'string', 'max:40'],   // automatic numbering: none until posted (DocumentNumbers)
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'prices_include_tax' => ['boolean'],
            'language' => ['nullable', 'in:en'],
            // The version the client read; a save is refused (409) if someone else saved in between.
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'not_in:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')->where('is_active', true)],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('is_active', true)],   // for stock lines
        ];
    }
}
