<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viral_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // VP-0001
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tech_team_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 16)->default('active')->index(); // active | completed
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('viral_package_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viral_package_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // article | social_post | reel | landing_page
            $table->unsignedTinyInteger('slot_number')->default(1);
            $table->string('title')->nullable();
            $table->string('stage', 16)->default('pending')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('landing_page_url')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('viral_package_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained('viral_package_deliverables')->cascadeOnDelete();
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viral_package_history');
        Schema::dropIfExists('viral_package_deliverables');
        Schema::dropIfExists('viral_packages');
    }
};
