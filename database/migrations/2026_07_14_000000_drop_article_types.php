<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'article_type_id')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropForeign(['article_type_id']);
                $table->dropColumn('article_type_id');
            });
        }

        Schema::dropIfExists('article_types');
    }

    public function down(): void
    {
        Schema::create('article_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('article_type_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }
};
