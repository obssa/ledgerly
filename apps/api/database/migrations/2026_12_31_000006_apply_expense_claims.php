<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/083_expense_claims.sql (Module 3): employee expense claims. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('083_expense_claims.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
