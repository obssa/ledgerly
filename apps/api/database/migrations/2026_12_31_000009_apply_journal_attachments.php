<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/086_journal_attachments.sql (Module 6): attachments on journal entries. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('086_journal_attachments.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
