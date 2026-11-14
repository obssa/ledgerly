<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/010_account_recovery.sql (SECURITY.md S3): one-time links for confirming an email
 * address and resetting a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('010_account_recovery.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Account security rules are never rolled back. Restore from backup instead.');
    }
};
