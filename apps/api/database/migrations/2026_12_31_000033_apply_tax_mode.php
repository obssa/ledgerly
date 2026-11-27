<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/110_tax_mode.sql: QuickBooks' "Amounts are": exclusive, inclusive or out of scope of tax. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('110_tax_mode.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
