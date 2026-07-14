<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('radius_m')->default(150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            // check-in location
            $table->decimal('in_lat', 10, 7)->nullable();
            $table->decimal('in_lng', 10, 7)->nullable();
            $table->unsignedInteger('in_accuracy')->nullable();
            $table->string('in_address')->nullable();
            $table->string('in_photo_path')->nullable();
            // check-out location
            $table->decimal('out_lat', 10, 7)->nullable();
            $table->decimal('out_lng', 10, 7)->nullable();
            $table->string('out_address')->nullable();
            $table->string('out_photo_path')->nullable();
            // derived
            $table->string('status', 16)->default('present'); // present|late|half_day
            $table->boolean('on_site')->default(false);
            $table->foreignId('office_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('work_minutes')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        // Geo-tag field visits
        Schema::table('visits', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('photo_path');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->unsignedInteger('accuracy')->nullable()->after('lng');
            $table->string('address')->nullable()->after('accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'accuracy', 'address']);
        });
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('office_locations');
    }
};
