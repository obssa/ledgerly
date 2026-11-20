<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/094_tags.sql (Module 1): custom tags. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('094_tags.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
