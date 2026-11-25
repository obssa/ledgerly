<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/106_saved_transaction_reports.sql: the transaction reports can be saved. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('106_saved_transaction_reports.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
