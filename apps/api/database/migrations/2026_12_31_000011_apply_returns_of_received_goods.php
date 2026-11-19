<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/088_returns_of_received_goods.sql (Module 3): supplier credits send back goods received on notes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('088_returns_of_received_goods.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
