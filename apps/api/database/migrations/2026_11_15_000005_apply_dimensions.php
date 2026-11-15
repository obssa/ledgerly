<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/014_dimensions.sql (Module 1): locations, classes and projects, and the rule that a line
 * names only its own company's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('014_dimensions.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
