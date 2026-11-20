<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/099_invoice_links.sql (Module 2): invoices seen online. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('099_invoice_links.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
