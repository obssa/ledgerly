<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/058_credit_note_returns.sql (Module 4): goods returned on a credit note. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('058_credit_note_returns.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
