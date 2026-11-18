<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/050_invoice_retention.sql (Module 9a): retention held and released. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('050_invoice_retention.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
