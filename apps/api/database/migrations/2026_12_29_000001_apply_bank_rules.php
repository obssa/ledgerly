<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/076_bank_rules.sql (Module 5): bank rules. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('076_bank_rules.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
