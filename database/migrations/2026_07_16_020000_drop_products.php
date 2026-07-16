<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Products & Packages catalog. Invoice line items keep their own
 * description + price snapshot, so dropping product_id loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });
    }
};
