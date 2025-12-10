<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'scopes' => explode(',', env('SHOPIFY_SCOPES', 'read_products,write_products,read_orders,write_orders')),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', env('APP_URL').'/shopify/callback'),
    'api_version' => env('SHOPIFY_API_VERSION', '2024-10'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
    'app_host' => env('APP_URL', 'http://localhost'),
];
