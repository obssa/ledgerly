<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/085_barcodes.sql (Module 4): barcodes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('085_barcodes.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
