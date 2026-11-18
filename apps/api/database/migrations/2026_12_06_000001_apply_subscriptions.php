<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/053_subscriptions.sql (Module 11): subscriptions without a payment provider. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('053_subscriptions.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
