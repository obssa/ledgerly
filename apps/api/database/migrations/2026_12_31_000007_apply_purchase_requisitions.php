<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Applies database/schema/084_purchase_requisitions.sql (Module 3): purchase requisitions. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('084_purchase_requisitions.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};
