<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/045_saved_reports.sql (Module 10): saved and scheduled reports. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('045_saved_reports.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
