<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/095_leases.sql (Module 9): leases, as the lessee. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('095_leases.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
