<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/063_attachments.sql (S7): files attached to documents. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('063_attachments.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
