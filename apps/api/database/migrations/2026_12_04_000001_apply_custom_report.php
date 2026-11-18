<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/051_custom_report.sql (Module 10): the custom report can be saved. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('051_custom_report.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
