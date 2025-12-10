<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'shopify_order_number')) {
                $table->string('shopify_order_number')->nullable()->after('shopify_order_id');
            }
            if (!Schema::hasColumn('orders', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('sync_status');
            }
            if (!Schema::hasColumn('orders', 'sync_error')) {
                $table->text('sync_error')->nullable()->after('synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shopify_order_number', 'synced_at', 'sync_error']);
        });
    }
};