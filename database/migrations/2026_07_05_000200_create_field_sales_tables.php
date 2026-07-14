<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the salesperson
            $table->date('visit_date')->index();
            $table->string('visit_level', 32)->default('cold'); // pipeline stage at this visit
            $table->string('person_met')->nullable();
            $table->string('contact_phone')->nullable();
            $table->boolean('decision_maker_met')->default(false);
            $table->boolean('interested')->default(false);
            $table->boolean('follow_up_done')->default(false);
            $table->decimal('revenue_potential', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'visit_date']);
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('due_date')->index();
            $table->string('note')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending | done
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'due_date']);
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 8)->index(); // won | lost
            $table->decimal('actual_revenue', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->date('closed_at')->index();
            $table->timestamps();
        });

        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7)->index(); // YYYY-MM
            $table->unsignedInteger('visits_target')->nullable();
            $table->decimal('revenue_target', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('visits');
    }
};
