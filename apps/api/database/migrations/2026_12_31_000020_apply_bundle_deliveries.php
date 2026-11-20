<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/097_bundle_deliveries.sql (Module 4): bundles delivered on delivery notes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('097_bundle_deliveries.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
