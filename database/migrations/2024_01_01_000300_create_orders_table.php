<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
    $table->string('external_order_id')->nullable();
    $table->string('customer_name')->nullable();
    $table->string('customer_email')->nullable();
    $table->string('customer_phone')->nullable();              // <-- sans after()
    $table->string('shopify_order_id')->nullable();            // <-- sans after()
    $table->string('shopify_order_number')->nullable();        // <-- sans after()
    $table->decimal('amount', 10, 2)->nullable();
    $table->string('currency')->default('USD');
    $table->integer('quantity')->default(1);
    $table->string('promo_code')->nullable();
    $table->string('status')->default('pending');
    $table->string('sync_status')->default('not_synced');
    $table->string('source')->default('manual');
    $table->json('payload')->nullable();
    $table->text('error_message')->nullable();
    $table->text('sync_error')->nullable();                    // <-- sans after()
    $table->timestamp('synced_at')->nullable();
    $table->timestamps();
    $table->index(['shop_id', 'sync_status']);
});

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_variant_id');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
