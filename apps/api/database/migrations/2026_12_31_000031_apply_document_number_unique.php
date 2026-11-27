<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/108_document_number_unique.sql: custom numbers allowed per type, unique in any letter case. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('108_document_number_unique.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
