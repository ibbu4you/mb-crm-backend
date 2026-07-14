<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('slot_at');              // top-of-hour bucket this update covers
            $table->date('log_date')->index();         // = slot_at date, for fast day/range grouping
            $table->string('mode', 16)->default('working'); // working | meeting | break | blocked
            $table->text('note')->nullable();
            $table->string('link_type', 16)->nullable();    // lead | article | client
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('link_label')->nullable();       // snapshot label (survives deletes)
            $table->boolean('is_late')->default(false);      // submitted after the slot's grace window
            $table->timestamps();

            $table->unique(['user_id', 'slot_at']);
            $table->index(['user_id', 'log_date']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('last_reminder_slot_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('last_reminder_slot_at');
        });
        Schema::dropIfExists('work_logs');
    }
};
