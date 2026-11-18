<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/061_tax_depreciation.sql (Module 9c): a separate tax depreciation schedule. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('061_tax_depreciation.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
