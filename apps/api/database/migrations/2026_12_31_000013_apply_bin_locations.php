<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/090_bin_locations.sql (Module 4): bin locations. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('090_bin_locations.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
