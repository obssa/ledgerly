<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/052_tot_returns.sql (Module 7): turnover tax returns. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('052_tot_returns.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
