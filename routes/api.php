<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Shopify\WebhookController;
use App\Http\Controllers\Api\OrderApiController;

Route::post('/shopify/webhooks/products', [WebhookController::class, 'handleProduct']);
Route::post('/shopify/webhooks/orders', [WebhookController::class, 'handleOrder']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/orders', [OrderApiController::class, 'store']);
});
