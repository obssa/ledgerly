<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/069_goods_receipts.sql (Module 3): goods received notes and the three-way match. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('069_goods_receipts.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
