<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/046_invitations.sql (Module 11): one-time links to accept an invitation. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('046_invitations.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
