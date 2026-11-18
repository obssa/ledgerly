<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/068_purchase_orders.sql (Module 3): purchase orders. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('068_purchase_orders.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
