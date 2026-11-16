<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/039_tax_returns.sql (Module 7): filed tax returns, their settlement, and the lock a filed
 * return puts on its period for that tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('039_tax_returns.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
