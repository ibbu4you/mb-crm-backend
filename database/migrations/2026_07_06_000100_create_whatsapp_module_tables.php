<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable message templates (with {{1}} placeholders).
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 20)->default('marketing'); // marketing|utility|authentication
            $table->string('language', 10)->default('en');
            $table->string('header')->nullable();
            $table->text('body');
            $table->string('footer')->nullable();
            $table->json('buttons')->nullable(); // [{type, text, value}]
            $table->string('status', 20)->default('approved'); // draft|pending|approved|rejected
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Audience segments / broadcast lists.
        Schema::create('whatsapp_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('whatsapp_groups')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->timestamps();
            $table->unique(['group_id', 'phone']);
        });

        // Bulk broadcasts.
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('whatsapp_groups')->nullOnDelete();
            $table->text('message');
            $table->string('status', 20)->default('draft'); // draft|scheduled|sending|sent|failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->string('status', 20)->default('pending'); // pending|sent|failed|delivered|read
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // Track when an agent last read a conversation thread (inbox unread).
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('agent_read_at')->nullable()->after('last_activity_at');
            $table->string('contact_name')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['agent_read_at', 'contact_name']);
        });
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
        Schema::dropIfExists('whatsapp_group_members');
        Schema::dropIfExists('whatsapp_groups');
        Schema::dropIfExists('whatsapp_templates');
    }
};
