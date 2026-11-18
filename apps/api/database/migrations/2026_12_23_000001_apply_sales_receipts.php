<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/070_sales_receipts.sql (Module 2): sales receipts, the cash sale. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('070_sales_receipts.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
