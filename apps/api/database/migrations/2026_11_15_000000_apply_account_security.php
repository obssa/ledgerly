<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/009_account_security.sql (SECURITY.md S2): when the authenticator was confirmed,
 * the last accepted code step, and one-time recovery codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('009_account_security.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Account security rules are never rolled back. Restore from backup instead.');
    }
};
