# Sellify

Sellify est une application Laravel 12 + Filament qui permet de créer, planifier et synchroniser des commandes Shopify (manuales ou Faker) sur plusieurs boutiques via une app Shopify (OAuth + webhooks) et des workers queue.

## Stack & choix
- Laravel 12, Filament 3 pour l’admin rapide et policy-friendly.
- MySQL 8 (phpMyAdmin fourni), Redis pour queues/scheduler, Docker Compose pour l’orchestration.
- Admin API Shopify (REST) pour la création d’orders (plus simple et stable pour `orders.json`), webhooks pour rester synchronisé.
- Sanctum pour les tokens API externes, Spatie Permission pour les rôles (super-admin, shop-owner, shop-collaborator).

## Démarrage rapide (Docker)
1) Copier `.env.example` vers `.env` et remplir les clés Shopify (voir plus bas). Les hosts par défaut `DB_HOST=db` / `REDIS_HOST=redis` sont déjà alignés sur docker-compose.  
2) Build + lancement (installe Composer et build Vite dans l’image) : `docker compose up --build -d`  
3) Générer la clé si `APP_KEY` est vide : `docker compose exec app php artisan key:generate`  
4) Migrations + seed (super-admin `admin@sellify.test` / `password`) : `docker compose exec app php artisan migrate --seed`  
5) Services de fond : le `worker` (queues) et le `scheduler` tournent en containers dédiés.  
6) (Optionnel) Vite en HMR : `docker compose --profile dev up vite` (port 5173).  
7) Accès : http://localhost:8080 (Filament admin sur `/admin`).  

Tests (SQLite in-memory) : `docker compose exec app php artisan test`.

## Variables d’environnement clés
- `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_WEBHOOK_SECRET` (secret HMAC webhook = secret API).  
- `SHOPIFY_REDIRECT_URI` (ex: `https://sellify.test/shopify/callback`).  
- `SHOPIFY_SCOPES` : `read_products,write_products,read_orders,write_orders,read_fulfillments,write_fulfillments`.  
- Base de données : `DB_CONNECTION=mysql`, hôte `db`, user/pass `sellify/secret` (docker).  
- Queue : `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis`.  

## Modèle de données (migrations complètes)
- `shops` (+ `shop_sync_windows`) : boutique, domaine Shopify, token chiffré, timezone, fenêtres d’envoi.  
- `products` : miroir des variants Shopify (id produit + variant_id, titre, prix, payload).  
- `orders` + `order_items` : statut interne (`pending|queued|synced|shipped|delivered|cancelled`), statut de sync (`not_synced|syncing|synced|failed`), source (`manual|api|autonomous`).  
- `autonomous_scenarios` + pivot produits : plages horaires, intervalle min/max, locale Faker, quantités, montants, `next_run_at`.  
- `roles/permissions` (Spatie), `personal_access_tokens` (Sanctum), `sessions`.  

## Architecture (DDD light)
- Services Shopify :  
  - `ShopifyAuthService` (OAuth + validation HMAC + enregistrement boutique).  
  - `ShopifyProductService` (sync produits via REST products.json).  
  - `ShopifyOrderService` (création d’ORDER Shopify sur la boutique d’origine).  
  - `WebhookHandlerService` (validation HMAC + maj produits/orders depuis webhooks).  
- Métier :  
  - `OrderSyncService` (pousse une commande Sellify vers Shopify et maintient les statuts).  
  - `AutonomousOrderGeneratorService` (vérifie fenêtre horaire, choisit produit Shopify, Faker selon locale, calcule quantité/montant, planifie `next_run_at`).  
- Jobs queue :  
  - `SyncOrderToShopify` (envoie une commande si la fenêtre de la boutique est ouverte).  
  - `GenerateAutonomousOrder` (génère + enfile la sync).  
- Scheduler (bootstrap/app.php) :  
  - `orders:sync-queued` toutes les 5 min.  
  - `scenarios:run-autonomous` chaque minute.  
- Filament Resources : Users, Shops (+ fenêtres horaires), Products (lecture seule), Orders (création manuelle avec produits Shopify), AutonomousScenarios, ApiTokens.  

## Intégration Shopify (processus complet)
### Côté Shopify (admin partenaire)
1. Créer une app custom.  
2. Ajouter les scopes : `read_products,write_products,read_orders,write_orders,read_fulfillments,write_fulfillments`.  
3. URL de redirection OAuth : `https://<sellify_host>/shopify/callback`.  
4. Configurer les webhooks (JSON) pointant vers Sellify :  
   - `products/create`, `products/update`, `products/delete` → `POST https://<sellify_host>/api/shopify/webhooks/products`  
   - `orders/create`, `orders/updated` → `POST https://<sellify_host>/api/shopify/webhooks/orders`  
   Secret HMAC = `SHOPIFY_WEBHOOK_SECRET` (même que API secret).  

### Côté Sellify
1. Renseigner les variables `.env` Shopify.  
2. Se connecter en super-admin via `/admin` puis créer d’autres utilisateurs/roles si besoin.  
3. Connecter une boutique : bouton “Connect Shopify” (route `/shopify/connect?shop=monstore.myshopify.com`).  
   - Redirection OAuth → validation HMAC + state → échange du code contre access_token → enregistrement `shops` → sync produits initiale.  
4. Vérifier les produits dans Filament (`Products`). Ils sont la seule source pour créer des orders.  
5. (Optionnel) Définir des fenêtres horaires d’envoi dans la fiche boutique (`Sync Windows` relation).  

### Mapping Sellify → Shopify Order
- Sellify `orders` / `order_items` -> Shopify `order` payload :  
  - `line_items[]` : `variant_id` = `order_items.shopify_variant_id`, `quantity`, `price` (ou prix Shopify).  
  - `customer.first_name` + `email` issus de la commande Faker/manuelle.  
  - `financial_status` fixé à `paid`, `currency` idem commande.  
- Statuts internes :  
  - `status`: flux métier (pending/queued/synced/shipped/delivered/cancelled).  
  - `sync_status`: suivi d’API (not_synced/syncing/synced/failed).  
  - Shopify → Sellify (webhooks orders): financial_status/refunds => `cancelled`, fulfillment_status => `delivered`, sinon `synced`.  

## Mode autonome (Faker + produits Shopify)
1. Créer un scénario (`AutonomousScenarioResource`) en sélectionnant : boutique, produits Shopify (multi-select), plage horaire locale, intervalle aléatoire min/max (secondes), quantités min/max, montants optionnels, locale Faker (mapping configurable `config/autonomous.php`), activer `is_active`.  
2. Le scheduler `scenarios:run-autonomous` regarde `next_run_at` et la plage horaire ; si actif, il enfile `GenerateAutonomousOrder`.  
3. Le job génère un client Faker cohérent, choisit un produit attaché, calcule quantité/montant, crée l’order Sellify (`source=autonomous`, `status=queued`, `sync_status=not_synced`), met à jour `next_run_at`, puis enfile `SyncOrderToShopify`.  

## Envoi automatique & fenêtres horaires
- Chaque boutique peut définir plusieurs fenêtres (`shop_sync_windows`).  
- Le command `orders:sync-queued` n’enfile un ordre que si `shop->isWithinSyncWindow()` et `auto_sync_enabled=true`.  
- Les scénarios autonomes appliquent en plus leur propre plage horaire.  

## Webhooks
- Endpoints : `/api/shopify/webhooks/products`, `/api/shopify/webhooks/orders`.  
- Validation : HMAC SHA256 base64 sur le body via `SHOPIFY_WEBHOOK_SECRET`.  
- Produits : upsert/delete des variants Shopify.  
- Orders : mise à jour du statut interne et payload de référence.  

## Routage principal
- `/shopify/connect?shop=monstore.myshopify.com` : démarre OAuth (state + HMAC).  
- `/shopify/callback` : échange du code, enregistre la boutique, sync produits.  
- `/orders/{order}/sync` (POST, auth) : pousse un order précis.  
- API (Sanctum) `/api/orders` : création d’ordre programmatique.  

## Développement & qualité
- Tests fournis : vérif HMAC OAuth/Webhook, génération Faker d’un order autonome.  
- Pour élargir : ajouter des tests d’API OAuth (mock Shopify), tests de webhooks produits/commande, tests de fenêtre horaire (shop + scénario) et job queue.  

## Sécurité & bonnes pratiques
- Tokens Shopify chiffrés (`casts` encrypted).  
- HMAC (OAuth + webhooks) validés, `state` anti-CSRF.  
- Rôles Spatie + gate super-admin (autorisation Filament).  
- Logs sur appels Shopify (création orders/produits).  
- Files d’attente pour lisser les erreurs/ratelimits; en cas d’échec la commande passe en `sync_status=failed` avec `error_message`.  

## Commandes utiles
- Sync commandes en attente : `php artisan orders:sync-queued --limit=50`  
- Scanner les scénarios autonomes : `php artisan scenarios:run-autonomous --limit=50`  
- Seeder roles + super-admin : `php artisan migrate --seed`  
- Build front (si besoin de Vite plus tard) : `npm install && npm run build`  

## Hypothèses
- Une “commande” Sellify correspond toujours à un ORDER Shopify (jamais draft_order).  
- Les produits sélectionnables proviennent uniquement de Shopify via sync ou webhooks.  
- Les montants Faker peuvent être forcés (min/max) ou dérivés du prix variant.  
- Fuseaux horaires côté boutique font foi pour les fenêtres et scénarios.  



### Pour lancer les workers pour les commandes auto

Dans ce projet, tout tourne dans le conteneur `app`. Utilise ces commandes dans un terminal à la racine du projet :

- **Worker principal (queue par défaut)** – traite les jobs génériques, y compris la génération d’ordres autonomes :

  ```bash
  docker-compose exec app php artisan queue:work --queue=default
  ```

- **Worker dédié à la sync Shopify** – si tu veux isoler la file de sync (facultatif, selon ta config QUEUE) :

  ```bash
  docker-compose exec app php artisan queue:work --queue=sync-order-to-shopify
  ```

- **Scan des scénarios autonomes** – à lancer via cron (ou manuellement) pour en filer les générations d’ordres :

  ```bash
  docker-compose exec app php artisan scenarios:run-autonomous --limit=50
  ```

En production, configure un cron pour exécuter régulièrement `scenarios:run-autonomous` (par exemple toutes les minutes) et laisse au moins un `queue:work` tourner en continu.