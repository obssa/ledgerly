<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/077_recurring_invoices.sql (Module 2): recurring invoices. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('077_recurring_invoices.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
