<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/066_staff.sql (S8): the staff console. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('066_staff.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
