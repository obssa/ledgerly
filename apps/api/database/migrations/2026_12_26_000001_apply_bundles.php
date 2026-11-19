<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/073_bundles.sql (Module 4): bundles and kits. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('073_bundles.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
