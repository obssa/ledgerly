<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/104_test_companies.sql: test companies (real books their owners may empty and fill with the sample). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('104_test_companies.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
