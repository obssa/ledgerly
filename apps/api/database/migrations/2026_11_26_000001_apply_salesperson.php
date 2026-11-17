<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/044_salesperson.sql (Module 2/10): the salesperson on a sales document. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('044_salesperson.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
