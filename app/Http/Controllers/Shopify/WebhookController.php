<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\WebhookHandlerService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(private WebhookHandlerService $handler)
    {
    }

    public function handleProduct(Request $request)
    {
        $payload = $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        if (!$this->handler->verify($payload, $hmac)) {
            return response('Invalid signature', Response::HTTP_FORBIDDEN);
        }

        $data = $request->json()->all();
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if ($topic === 'products/delete') {
            $this->handler->handleProductDeleted($data);
        } else {
            $this->handler->handleProductWebhook($data, $shopDomain);
        }

        return response('ok');
    }

    public function handleOrder(Request $request)
    {
        $payload = $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        if (!$this->handler->verify($payload, $hmac)) {
            return response('Invalid signature', Response::HTTP_FORBIDDEN);
        }

        $data = $request->json()->all();
        $this->handler->handleOrderWebhook($data);

        return response('ok');
    }
}
