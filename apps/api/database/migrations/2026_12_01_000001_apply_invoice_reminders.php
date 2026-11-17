<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/048_invoice_reminders.sql (Module 2): payment reminders for overdue invoices. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('048_invoice_reminders.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
