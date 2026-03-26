<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyAuthService
{
    // public function buildInstallUrl(string $shopDomain, ?string $state = null): string
    // {
    //     $scopes = implode(',', Config::get('shopify.scopes', []));
    //     $redirect = urlencode(Config::get('shopify.redirect_uri'));
    //     $apiKey = Config::get('shopify.api_key');
    //     $stateParam = $state ? "&state={$state}" : '';

    //     $normalizedShop = trim(preg_replace('#^https?://#', '', $shopDomain), '/');

    //     if (blank($apiKey)) {
    //         throw new RuntimeException('SHOPIFY_API_KEY is not set');
    //     }

    //     if (blank($normalizedShop)) {
    //         throw new RuntimeException('Shop domain is required');
    //     }

    //     return "https://{$normalizedShop}/admin/oauth/authorize?client_id={$apiKey}&scope={$scopes}&redirect_uri={$redirect}{$stateParam}";
    // }
    public function buildInstallUrl(string $shopDomain, ?string $state = null): string
{
    $scopes = implode(',', Config::get('shopify.scopes', []));
    $redirect = Config::get('shopify.redirect_uri'); // ← Retirez urlencode ici
    $apiKey = Config::get('shopify.api_key');
    $stateParam = $state ? "&state={$state}" : '';

    $normalizedShop = trim(preg_replace('#^https?://#', '', $shopDomain), '/');

    if (blank($apiKey)) {
        throw new RuntimeException('SHOPIFY_API_KEY is not set');
    }

    if (blank($normalizedShop)) {
        throw new RuntimeException('Shop domain is required');
    }

    // URL encode seulement le redirect_uri dans l'URL finale
    $encodedRedirect = urlencode($redirect);
    
    $url = "https://{$normalizedShop}/admin/oauth/authorize?client_id={$apiKey}&scope={$scopes}&redirect_uri={$encodedRedirect}{$stateParam}";
    
    \Log::info('Building install URL', [
        'redirect_uri' => $redirect,
        'encoded_redirect_uri' => $encodedRedirect,
        'full_url' => $url
    ]);

    return $url;
}

    public function verifyHmac(array $query): bool
    {
        $hmac = $query['hmac'] ?? '';
        unset($query['hmac'], $query['signature']);
        ksort($query);

        $computed = hash_hmac('sha256', urldecode(http_build_query($query)), Config::get('shopify.api_secret'));

        return hash_equals($hmac, $computed);
    }

    public function exchangeToken(string $shopDomain, string $code): string
    {
        $response = Http::asForm()->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id' => Config::get('shopify.api_key'),
            'client_secret' => Config::get('shopify.api_secret'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to exchange Shopify token: '.$response->body());
        }

        $token = $response->json('access_token');

        if (empty($token)) {
             \Illuminate\Support\Facades\Log::error('Shopify token exchange succeeded but returned no token', [
                 'body' => $response->json(),
                 'headers' => $response->headers()
             ]);
        } else {
             \Illuminate\Support\Facades\Log::info('Shopify token exchange successful', [
                 'shop' => $shopDomain,
                 'token_preview' => substr($token, 0, 5) . '...'
             ]);
        }

        return $token;
    }

    public function connectShop(User $user, string $shopDomain, string $code, array $scopes): Shop
    {
        $token = $this->exchangeToken($shopDomain, $code);

        $shop = Shop::updateOrCreate(
            ['shopify_domain' => $shopDomain],
            [
                'name' => Str::before($shopDomain, '.'),
                'access_token' => $token,
                'scopes' => $scopes,
                'status' => 'connected',
            ]
        );

        $shop->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $shop;
    }
}
