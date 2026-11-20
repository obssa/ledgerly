<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/096_serial_numbers.sql (Module 4): serial numbers. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('096_serial_numbers.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
