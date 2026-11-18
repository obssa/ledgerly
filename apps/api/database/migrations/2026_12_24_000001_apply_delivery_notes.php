<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/071_delivery_notes.sql (Module 2): delivery notes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('071_delivery_notes.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
