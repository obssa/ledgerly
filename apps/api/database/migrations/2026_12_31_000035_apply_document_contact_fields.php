<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/112_document_contact_fields.sql: QuickBooks' addresses, email Cc and Bcc, shipping and message on statement. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('112_document_contact_fields.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
