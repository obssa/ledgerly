<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/059_supplier_returns.sql (Module 4): goods returned to a supplier on a supplier credit. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('059_supplier_returns.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
