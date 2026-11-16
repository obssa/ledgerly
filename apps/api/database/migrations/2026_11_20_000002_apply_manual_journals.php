<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/032_manual_journals.sql (Module 6): manual journal entries never touch the accounts kept
 * by documents (receivables, payables, stock, undeposited funds, retained earnings).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('032_manual_journals.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
