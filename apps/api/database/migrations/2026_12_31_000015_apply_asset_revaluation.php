<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/092_asset_revaluation.sql (Module 9c): revaluation of fixed assets. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('092_asset_revaluation.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
