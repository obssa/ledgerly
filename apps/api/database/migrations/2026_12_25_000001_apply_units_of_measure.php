<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/072_units_of_measure.sql (Module 4): units of measure. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('072_units_of_measure.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
