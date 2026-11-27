<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/107_cross_currency_payments.sql: a foreign payment through an account in the company's currency. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('107_cross_currency_payments.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
