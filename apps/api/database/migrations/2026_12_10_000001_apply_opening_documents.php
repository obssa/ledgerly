<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/057_opening_documents.sql (Module 1): open invoices and bills brought in at conversion. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('057_opening_documents.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
