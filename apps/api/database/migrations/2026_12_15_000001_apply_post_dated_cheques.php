<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/062_post_dated_cheques.sql (Module 5): post-dated cheques. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('062_post_dated_cheques.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
