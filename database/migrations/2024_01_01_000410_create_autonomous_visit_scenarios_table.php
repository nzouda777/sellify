<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('autonomous_visit_scenarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('location_label')->nullable();
            $table->string('faker_locale')->nullable();
            $table->string('target_url');
            $table->time('window_start_time')->nullable();
            $table->time('window_end_time')->nullable();
            $table->unsignedInteger('min_interval_seconds')->default(60);
            $table->unsignedInteger('max_interval_seconds')->default(300);
            $table->unsignedInteger('target_visits')->nullable();
            $table->unsignedInteger('generated_visits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('next_run_at')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonomous_visit_scenarios');
    }
};
