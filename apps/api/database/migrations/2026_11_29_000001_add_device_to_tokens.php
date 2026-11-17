<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Module 11: each signed-in session remembers the device and address it was signed in from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device', 'ip_address']);
        });
    }
};
