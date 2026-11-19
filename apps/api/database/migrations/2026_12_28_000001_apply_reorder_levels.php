<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/075_reorder_levels.sql (Module 4): minimum and maximum levels. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('075_reorder_levels.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
