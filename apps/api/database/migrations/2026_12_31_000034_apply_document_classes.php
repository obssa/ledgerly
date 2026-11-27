<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/111_document_classes.sql: classes and locations on documents and lines, and a line's service date. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('111_document_classes.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
