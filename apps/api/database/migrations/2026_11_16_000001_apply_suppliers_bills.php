<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/019_suppliers_bills.sql (Module 3): supplier payout details, default account and withholding
 * status; the supplier's reference on a bill; sales documents for customers and purchase documents for suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('019_suppliers_bills.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
