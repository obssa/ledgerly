<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The accounting core is written in plain PostgreSQL (database/schema/*.sql) because it relies on
 * features Laravel's schema builder does not express: hash partitioning, deferred constraint triggers,
 * immutability and state-machine triggers, and row-level security. Each file is applied once, in order.
 */
return new class extends Migration
{
    private const FILES = [
        '001_tenancy.sql',
        '002_ledger.sql',
        '003_business.sql',
        '004_compliance_audit.sql',
        '005_history.sql',
        '006_state_machines.sql',
        '007_rls.sql',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Ledgerly requires PostgreSQL 16+ (row-level security, constraint triggers).');
        }

        foreach (self::FILES as $file) {
            DB::unprepared(SchemaSql::load($file));
        }
    }

    public function down(): void
    {
        // Financial records are never dropped by a rollback. Restore from backup instead.
        throw new RuntimeException('The core ledger schema cannot be rolled back.');
    }
};
