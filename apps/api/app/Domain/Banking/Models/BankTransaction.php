<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a bank, wallet or cash statement: what the bank says happened. Reviewed by matching it to an entry already
 * in the books, categorising it (a new entry), or excluding it with a reason (027). Never edited, never deleted.
 *
 * @property string $status imported (to review) | matched | categorised | excluded | reconciled
 */
class BankTransaction extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    public const STATUSES = ['imported', 'matched', 'categorised', 'excluded', 'reconciled'];

    protected $fillable = ['account_id', 'posted_on', 'description', 'amount', 'balance_after', 'source', 'external_id', 'reference', 'imported_by'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['suggestion' => 'array'];   // posted_on stays the Y-m-d string the database gives
    }
}
