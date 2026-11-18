<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/055_stock_project_issue.sql (Module 9a): stock issued to a project. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('055_stock_project_issue.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
