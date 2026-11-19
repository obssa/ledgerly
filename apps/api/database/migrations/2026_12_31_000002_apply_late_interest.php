<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/079_late_interest.sql (Module 2): interest on late payment. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('079_late_interest.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
