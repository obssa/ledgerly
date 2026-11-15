<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/018_demo_accounts.sql: the list of shared demo accounts, written only by the owner role.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('018_demo_accounts.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
