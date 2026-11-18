<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/056_quotes.sql (Module 2): quotes and sales orders. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('056_quotes.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
