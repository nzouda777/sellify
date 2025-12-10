<?php

namespace Tests\Unit;

use App\Services\Shopify\ShopifyAuthService;
use App\Services\Shopify\WebhookHandlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyHmacTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_hmac_verification(): void
    {
        config(['shopify.api_secret' => 'secret']);
        $service = new ShopifyAuthService();
        $params = [
            'code' => '123',
            'shop' => 'demo.myshopify.com',
            'timestamp' => '123456',
        ];
        $params['hmac'] = hash_hmac('sha256', http_build_query($params), 'secret');

        $this->assertTrue($service->verifyHmac($params));
    }

    public function test_webhook_hmac_verification(): void
    {
        config(['shopify.webhook_secret' => 'secret']);
        $handler = new WebhookHandlerService();
        $payload = json_encode(['id' => 1]);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'secret', true));

        $this->assertTrue($handler->verify($payload, $hmac));
    }
}
