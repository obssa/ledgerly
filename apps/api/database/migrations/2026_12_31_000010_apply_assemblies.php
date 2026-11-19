<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/087_assemblies.sql (Module 4): assemblies. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('087_assemblies.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
