<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/080_price_lists.sql (Module 2): customer groups and price lists. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('080_price_lists.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
