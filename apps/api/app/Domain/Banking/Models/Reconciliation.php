<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One bank statement agreed with the books (028): its date and ending balance, and the entries it shows (cleared).
 * draft while it is being worked on; completed when the difference is zero, with its report saved; undone with a reason.
 *
 * @property string $status draft | completed | undone
 */
class Reconciliation extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'statement_date', 'opening_balance', 'ending_balance', 'created_by'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['report' => 'array'];   // statement_date stays the Y-m-d string the database gives
    }
}
