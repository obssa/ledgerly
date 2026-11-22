<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/102_lifecycle_guards.sql guards for lifecycles the database did not check. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('102_lifecycle_guards.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
