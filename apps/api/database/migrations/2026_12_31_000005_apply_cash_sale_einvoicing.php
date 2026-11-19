<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/082_cash_sale_einvoicing.sql (Module 2): cash sales sent to the tax authority, and credited. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('082_cash_sale_einvoicing.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
