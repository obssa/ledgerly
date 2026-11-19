<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/081_credit_note_delivered_returns.sql (Module 2): credit notes bring back goods delivered on notes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('081_credit_note_delivered_returns.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
