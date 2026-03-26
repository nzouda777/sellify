<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Services\Shopify\ShopifyAuthService;
use App\Services\Shopify\ShopifyProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Config;
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

    /**
 * Point d'entrée initial depuis Shopify (installation)
 */
public function install(Request $request): RedirectResponse
{
    $shop = $request->query('shop');
    $hmac = $request->query('hmac');

    // Si aucun paramètre shop, afficher une page d'accueil ou d'erreur
    if (!$shop) {
        return view('welcome'); // ou une page d'installation
    }

    // Vérifier le HMAC si présent
    // if ($hmac && !$this->authService->verifyHmac($request->all())) {
    //     abort(Response::HTTP_FORBIDDEN, 'Invalid HMAC signature');
    // }

    // Générer un state de sécurité
    $state = str()->random(32);
    $request->session()->put('shopify_state', $state);
    $request->session()->put('shopify_shop', $shop);

    // Construire l'URL OAuth et rediriger
    $installUrl = $this->authService->buildInstallUrl($shop, $state);
     
    // LOG DE DIAGNOSTIC
    \Log::info('Install redirect', [
        'api_key_from_config' => config('shopify.api_key'),  // ← Ou utilisez config() au lieu de Config::get()
        'app_url_from_config' => config('app.url'),
        'redirect_uri_from_config' => config('shopify.redirect_uri'),
        'install_url' => $installUrl,
    ]);

    return redirect()->away($installUrl);
}

    // public function handleCallback(Request $request)
    // {
    //     $data = $request->all();

    //     if (!$this->authService->verifyHmac($data)) {
    //         abort(Response::HTTP_FORBIDDEN, 'Invalid HMAC');
    //     }

    //     if ($request->get('state') !== $request->session()->pull('shopify_state')) {
    //         abort(Response::HTTP_FORBIDDEN, 'Invalid state parameter');
    //     }

    //     $shopDomain = $request->get('shop');
    //     $code = $request->get('code');
    //     $scopes = explode(',', $request->get('scope', ''));

    //     $user = Auth::user();

    //     if (!$user) {
    //         abort(Response::HTTP_UNAUTHORIZED, 'Login required before connecting a shop');
    //     }
    //     $shop = $this->authService->connectShop($user, $shopDomain, $code, $scopes);
    //     $this->productService->syncProducts($shop);

    //     Session::flash('success', 'Shopify store connected successfully');

    //     return redirect('/admin/shops/'.$shop->id.'/edit');
    // }
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

    // Récupérer ou créer l'utilisateur
    $user = Auth::user();
    
    if (!$user) {
        // Option A : Créer automatiquement un utilisateur lié au shop
        $user = \App\Models\User::firstOrCreate(
            ['email' => $shopDomain], // ou extraire l'email du shop
            [
                'name' => str()->before($shopDomain, '.'),
                'password' => bcrypt(str()->random(32)),
            ]
        );
        
        Auth::login($user);
        
        // Option B : Rediriger vers inscription/connexion
        // session(['shopify_pending_install' => compact('shopDomain', 'code', 'scopes')]);
        // return redirect()->route('register');
    }

    $shop = $this->authService->connectShop($user, $shopDomain, $code, $scopes);
    $this->productService->syncProducts($shop);

    Session::flash('success', 'Shopify store connected successfully');

    return redirect('/admin/shops/'.$shop->id.'/edit');
}
}
