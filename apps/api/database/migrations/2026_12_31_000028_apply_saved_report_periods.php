<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/105_saved_report_periods.sql: saved reports roll with every report period. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('105_saved_report_periods.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
