<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/054_tax_reminders.sql (Module 7): reminders of tax returns that are due. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('054_tax_reminders.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
