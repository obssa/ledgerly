<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/109_payment_terms.sql: QuickBooks' Terms list, on customers, suppliers and documents. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('109_payment_terms.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
