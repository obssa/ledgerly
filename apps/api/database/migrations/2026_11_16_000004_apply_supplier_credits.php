<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/022_supplier_credits.sql (Module 3): a supplier credit names its bill, never credits more
 * than it, and is applied to bills of the same supplier and currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('022_supplier_credits.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
