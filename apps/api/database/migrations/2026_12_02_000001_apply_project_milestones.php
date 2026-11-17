<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/049_project_milestones.sql (Module 9a): milestones and progress billing. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('049_project_milestones.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
