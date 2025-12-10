<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_product_id');
            $table->string('shopify_variant_id');
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'shopify_variant_id']);
            $table->index(['shop_id', 'shopify_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
