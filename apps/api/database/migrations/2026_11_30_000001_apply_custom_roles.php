<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/047_custom_roles.sql (Module 11): what a custom role's permissions may be. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('047_custom_roles.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
