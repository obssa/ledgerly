<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/100_company_logos.sql (Module 1): the company logo. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('100_company_logos.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
