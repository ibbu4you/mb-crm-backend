<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loosen `source` from a fixed enum to free text on leads + contacts, so
 * integrations can label leads with any channel/campaign name (e.g. a specific
 * ad source) instead of only whatsapp|web|field|manual|referral. Existing
 * values are preserved as strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source', 40)->default('manual')->change();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('source', 40)->default('manual')->change();
        });
    }

    public function down(): void
    {
        // Reverting to the enum will fail if any custom source values exist.
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('source', ['whatsapp', 'web', 'field', 'manual', 'referral'])->default('manual')->change();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->enum('source', ['whatsapp', 'web', 'field', 'manual', 'referral'])->default('manual')->change();
        });
    }
};
