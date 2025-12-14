<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomous_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location_label')->nullable();
            $table->string('faker_locale')->default('en_US');
            $table->time('window_start_time')->default('00:00:00');
            $table->time('window_end_time')->default('23:59:59');
            $table->unsignedInteger('min_interval_seconds')->default(60);
            $table->unsignedInteger('max_interval_seconds')->default(300);
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->default(1);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->unsignedInteger('target_orders')->nullable();
            $table->decimal('fulfill_orders')->default(0);
            $table->unsignedInteger('generated_orders')->default(0);
            $table->string('promo_code')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
        Schema::create('autonomous_scenario_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('autonomous_scenario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();
            $table->unique(['autonomous_scenario_id', 'product_id'], 'scenario_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonomous_scenario_product');
        Schema::dropIfExists('autonomous_scenarios');
    }
};
