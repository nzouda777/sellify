<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Shopify\OAuthController;
use App\Http\Controllers\Shopify\OrderController;

// Gérer l'installation Shopify sur la route racine
Route::get('/', [OAuthController::class, 'install'])->name('shopify.install');

Route::get('/shopify/connect', [OAuthController::class, 'redirectToShopify'])->name('shopify.connect');
Route::get('/shopify/callback', [OAuthController::class, 'handleCallback'])->name('shopify.callback');

Route::middleware(['auth'])->group(function () {
    Route::post('/orders/{order}/sync', [OrderController::class, 'sync'])->name('orders.sync');
});