<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['company_id', 'code', 'name', 'type', 'subtype', 'system_key', 'parent_id', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Look up a system account (e.g. 'accounts_receivable') in the current company. */
    public static function system(string $key): self
    {
        return static::query()->where('system_key', $key)->firstOrFail();
    }

    /** Assets and expenses grow with debits; liabilities, equity and income grow with credits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
