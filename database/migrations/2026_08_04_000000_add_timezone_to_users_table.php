<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-employee IANA timezone (e.g. Asia/Kuala_Lumpur, Asia/Kolkata).
            // Null falls back to config('app.timezone'). Drives the employee's
            // work-log hours, attendance day boundary, reminders and displayed times.
            $table->string('timezone', 64)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
