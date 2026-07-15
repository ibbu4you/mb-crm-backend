<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('media_type', 12)->nullable()->after('body');  // image | video | document
            $table->string('media_url')->nullable()->after('media_type'); // publicly reachable — Meta fetches it
            $table->string('media_name')->nullable()->after('media_url'); // original filename (documents)
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'media_url', 'media_name']);
        });
    }
};
