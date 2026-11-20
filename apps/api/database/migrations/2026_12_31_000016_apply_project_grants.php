<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/093_project_grants.sql (Module 9a): grants for NGOs. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('093_project_grants.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
