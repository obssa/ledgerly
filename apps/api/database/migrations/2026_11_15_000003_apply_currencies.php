<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/012_currencies.sql (Module 1): the foreign currencies a company works in and its exchange
 * rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('012_currencies.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
