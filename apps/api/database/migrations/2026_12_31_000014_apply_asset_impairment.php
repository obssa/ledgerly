<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/091_asset_impairment.sql (Module 9c): impairment of fixed assets. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('091_asset_impairment.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
