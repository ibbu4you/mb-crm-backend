<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow more than one work-status update within the same hour. The old
 * unique(user_id, slot_at) forced a single entry per hourly slot (a second
 * submit overwrote the first). We drop it and keep a plain index for grouping;
 * an hour still counts once toward compliance, but every update is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_logs', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slot_at']);
            $table->index(['user_id', 'slot_at']);
        });
    }

    public function down(): void
    {
        Schema::table('work_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'slot_at']);
            // Note: re-adding the unique will fail if duplicate-hour rows already exist.
            $table->unique(['user_id', 'slot_at']);
        });
    }
};
