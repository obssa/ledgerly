<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/067_order_fulfilment.sql (Module 2): sales orders delivered in parts. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('067_order_fulfilment.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
