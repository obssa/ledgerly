<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\Currency;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A supplier's bill. Each line is a cost: an expense account, or a fixed asset or prepayment. A line with a stock item
 * goes to the inventory account instead (SaveInvoiceDraft) and brings the goods into the bill's warehouse on posting.
 */
final class SaveBillRequest extends FormRequest
{
    /** Checked before the rules, so a role that may not save bills is refused (403), not told what is missing (422). */
    public function authorize(): bool
    {
        $membership = app(CurrentCompany::class)->membership();

        return $membership !== null && $membership->can($this->permission());
    }

    protected function failedAuthorization(): never
    {
        throw new HttpException(403, "Your role does not allow {$this->permission()}.");
    }

    /** Saving a draft needs bills.create; changing a posted bill needs transactions.edit. */
    private function permission(): string
    {
        $bill = $this->route('bill');

        return $bill instanceof Document && $bill->isPosted() ? 'transactions.edit' : 'bills.create';
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('is_vendor', true)],
            'reference' => ['nullable', 'string', 'max:60'],   // the supplier's own invoice number
            'number' => ['nullable', 'string', 'max:40'],      // automatic numbering: none until posted
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'prices_include_tax' => ['boolean'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'not_in:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('is_active', true)
                ->where(fn (Builder $w) => $w->where('type', 'expense')->orWhere(fn (Builder $a) => $a->where('type', 'asset')->whereIn('subtype', ['fixed_asset', 'prepayment']))))],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->whereIn('kind', ['vat', 'tot'])],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')->where('is_active', true)],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('is_active', true)],   // where stock lines arrive
        ];
    }

    public function messages(): array
    {
        return ['lines.*.account_id.exists' => 'Choose an expense account, or a fixed asset or prepayment account.'];
    }
}
