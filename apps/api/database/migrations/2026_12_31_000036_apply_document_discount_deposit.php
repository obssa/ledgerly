<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/113_document_discount_deposit.sql: QuickBooks' discount and deposit on sales documents. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('113_document_discount_deposit.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
