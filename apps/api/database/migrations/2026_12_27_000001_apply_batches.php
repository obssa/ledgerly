<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/074_batches.sql (Module 4): batches and expiry. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('074_batches.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
