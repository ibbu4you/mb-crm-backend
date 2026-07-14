<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Encrypted key-value settings store (WhatsApp creds, branding, thresholds…)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        // Recipients that get pinged when a new WhatsApp lead lands.
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->string('phone');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Chatbot FSM state per sender phone.
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('state', 40)->default('new');
            $table->json('data')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        // Inbound / outbound message log.
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('direction', 8); // in | out
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_numbers');
        Schema::dropIfExists('settings');
    }
};
