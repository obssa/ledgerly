<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/008_access_hardening.sql (security review, 14 Nov 2026): role-aware membership
 * and company policies, row-level security on users, the audit-check limit. The core-schema migration
 * stopped at 007, so a database built with `php artisan migrate` was missing these rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('008_access_hardening.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Access rules are never rolled back. Restore from backup instead.');
    }
};
