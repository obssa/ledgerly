<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/103_scheduled_payments.sql: supplier payments planned for a later day. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('103_scheduled_payments.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
