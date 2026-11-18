<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/060_landed_costs.sql (Module 4): landed costs. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('060_landed_costs.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
