<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/078_bad_debts.sql (Module 2): bad debts written off. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('078_bad_debts.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
