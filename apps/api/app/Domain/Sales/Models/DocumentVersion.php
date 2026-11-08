<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A saved snapshot of a posted document before an edit. Append-only (enforced by the database). */
class DocumentVersion extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'version', 'journal_entry_id', 'snapshot', 'reason', 'edited_by'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
