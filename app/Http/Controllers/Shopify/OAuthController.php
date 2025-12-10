<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\ShopifyAuthService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class OAuthController extends Controller
{
    public function __construct(
        private ShopifyAuthService $authService,
        private ShopifyProductService $productService
    )
    {
        $this->middleware('auth')->except(['handleCallback']);
    }

    public function redirectToShopify(Request $request): RedirectResponse
    {
        $shopDomain = $request->string('shop')->toString();

        abort_unless($shopDomain, Response::HTTP_BAD_REQUEST, 'Shop domain required');

        $state = str()->random(32);
        $request->session()->put('shopify_state', $state);

        $url = $this->authService->buildInstallUrl($shopDomain, $state);

        return redirect()->away($url);
    }

    public function handleCallback(Request $request)
    {
        $data = $request->all();

        if (!$this->authService->verifyHmac($data)) {
            abort(Response::HTTP_FORBIDDEN, 'Invalid HMAC');
        }

        if ($request->get('state') !== $request->session()->pull('shopify_state')) {
            abort(Response::HTTP_FORBIDDEN, 'Invalid state parameter');
        }

        $shopDomain = $request->get('shop');
        $code = $request->get('code');
        $scopes = explode(',', $request->get('scope', ''));

        $user = Auth::user();

        if (!$user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Login required before connecting a shop');
        }
        $shop = $this->authService->connectShop($user, $shopDomain, $code, $scopes);
        $this->productService->syncProducts($shop);

        Session::flash('success', 'Shopify store connected successfully');

        return redirect('/admin/shops/'.$shop->id.'/edit');
    }
}
