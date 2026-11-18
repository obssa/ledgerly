<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/064_fifo.sql (Module 4): FIFO as an option per item. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('064_fifo.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
