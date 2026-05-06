<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Product;
use App\Models\Shop;
use App\Services\OrderSyncService;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
    
    // Propriété pour stocker temporairement les items
    protected array $itemsToCreate = [];
    // Propriété pour stocker l'état du formulaire
    protected bool $shouldSync = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            Log::info('=== DEBUT mutateFormDataBeforeCreate ===');
            Log::info('Données reçues:', $data);

            $totalAmount = 0;
            $totalQuantity = 0;
            $discountAmount = 0;
            $discountPercent = isset($data['promo_discount_percentage'])
                ? max(0, min(100, (float) $data['promo_discount_percentage']))
                : 0;

            // Vérification de la présence des items
            if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
                Log::error('Aucun item trouvé dans les données');
                throw new \Exception('Veuillez ajouter au moins un article à la commande');
            }

            // Traitement des items
            foreach ($data['items'] as $index => &$item) {
                Log::info("Traitement de l'item $index:", $item);

                if (!isset($item['product_id'])) {
                    Log::error("Product_id manquant pour l'item $index");
                    throw new \Exception("Produit manquant pour l'article " . ($index + 1));
                }

                $product = Product::find($item['product_id']);
                
                if (!$product) {
                    Log::error("Produit non trouvé: {$item['product_id']}");
                    throw new \Exception("Produit introuvable pour l'article " . ($index + 1));
                }

                Log::info("Produit trouvé:", [
                    'id' => $product->id,
                    'name' => $product->name ?? $product->title,
                    'price' => $product->price,
                    'shopify_variant_id' => $product->shopify_variant_id
                ]);

                // Attribution des valeurs
                $item['shopify_variant_id'] = $product->shopify_variant_id;
                $item['unit_price'] = $item['unit_price'] ?? $product->price ?? 0;
                $item['quantity'] = $item['quantity'] ?? 1;
                
                $lineTotal = floatval($item['unit_price']) * intval($item['quantity']);
                $item['total_price'] = $lineTotal;
                
                $totalAmount += $lineTotal;
                $totalQuantity += intval($item['quantity']);

                Log::info("Item $index traité:", [
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'total_price' => $item['total_price']
                ]);
            }

            // Calculs totaux
            if (!empty($data['promo_code']) && $discountPercent > 0) {
                $discountAmount = round($totalAmount * ($discountPercent / 100), 2);
                $totalAmount = max($totalAmount - $discountAmount, 0);
            }

            $data['amount'] = round($totalAmount, 2);
            $data['quantity'] = $totalQuantity;
            $data['status'] = $data['status'] ?? 'pending';
            $data['sync_status'] = 'not_synced';
            $data['promo_discount_percentage'] = $discountPercent;

            Log::info('Totaux calculés:', [
                'amount' => $totalAmount,
                'quantity' => $totalQuantity,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
            ]);

            // Vérification du shop_id
            if (empty($data['shop_id'])) {
                Log::error('Shop_id manquant');
                throw new \Exception('Shop ID manquant. Veuillez sélectionner un magasin.');
            }

            $shop = Shop::find($data['shop_id']);
            if (!$shop) {
                Log::error("Shop non trouvé: {$data['shop_id']}");
                throw new \Exception('Magasin introuvable');
            }

            Log::info('Shop validé:', ['id' => $shop->id, 'name' => $shop->name ?? 'N/A']);

            // Construction du payload avec l'adresse de livraison
            $shippingAddress = [
                'address1' => $data['shipping_address']['address1'] ?? '',
                'city' => $data['shipping_address']['city'] ?? '',
                'zip' => $data['shipping_address']['zip'] ?? '',
                'country' => $data['shipping_address']['country'] ?? 'France',
            ];

            $data['payload'] = [
                'shipping_address' => $shippingAddress,
                'customer_info' => [
                    'name' => $data['customer_name'] ?? '',
                    'email' => $data['customer_email'] ?? '',
                    'phone' => $data['customer_phone'] ?? '',
                ],
                'discount' => [
                    'code' => $data['promo_code'] ?? null,
                    'percent' => $discountPercent,
                    'amount' => $discountAmount,
                ],
                'created_from' => 'filament_admin',
                'created_at' => now()->toIso8601String(),
            ];

            Log::info('Payload construit:', $data['payload']);

            // Stocker les items et sync_now pour plus tard
            $this->itemsToCreate = $data['items'];
            $this->shouldSync = $data['sync_now'] ?? false;

            // Nettoyage des données temporaires
            unset($data['shipping_address']);
            unset($data['items']);
            unset($data['sync_now']);

            Log::info('Données finales pour création:', array_keys($data));
            Log::info('Items à créer après:', ['count' => count($this->itemsToCreate)]);
            Log::info('=== FIN mutateFormDataBeforeCreate (SUCCESS) ===');

            return $data;

        } catch (\Throwable $e) {
            Log::error('=== ERREUR dans mutateFormDataBeforeCreate ===');
            Log::error('Message: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            
            Notification::make()
                ->title('Erreur de préparation')
                ->body($e->getMessage())
                ->danger()
                ->duration(10000)
                ->send();

            throw $e;
        }
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            Log::info('=== DEBUT handleRecordCreation ===');
            Log::info('Données à insérer:', $data);

            DB::beginTransaction();

            // Créer la commande
            $record = static::getModel()::create($data);

            Log::info('Commande créée avec succès:', [
                'id' => $record->id,
                'customer_name' => $record->customer_name,
                'amount' => $record->amount,
            ]);

            // Créer les items dans la table order_items
            if (!empty($this->itemsToCreate)) {
                foreach ($this->itemsToCreate as $item) {
                    $record->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'shopify_variant_id' => $item['shopify_variant_id'] ?? null,
                    ]);
                }
                
                Log::info('Items créés dans order_items:', ['count' => count($this->itemsToCreate)]);
            }

            DB::commit();

            Log::info('=== FIN handleRecordCreation (SUCCESS) ===');

            return $record;

        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('=== ERREUR dans handleRecordCreation ===');
            Log::error('Message: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            Log::error('SQL Error:', [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);

            Notification::make()
                ->title('Erreur de création')
                ->body('Impossible de créer la commande: ' . $e->getMessage())
                ->danger()
                ->duration(10000)
                ->send();

            throw $e;
        }
    }

    protected function afterCreate(): void
    {
        try {
            Log::info('=== DEBUT afterCreate ===');
            Log::info('Commande créée ID: ' . $this->record->id);
            Log::info('Sync demandé: ' . ($this->shouldSync ? 'OUI' : 'NON'));

            if ($this->shouldSync) {
                Log::info('Tentative de synchronisation avec Shopify...');
                
                try {
                    $syncService = App::make(OrderSyncService::class);
                    $syncService->syncToShopify($this->record);
                    
                    Log::info('Synchronisation Shopify réussie');
                    
                    Notification::make()
                        ->title('✅ Commande créée et synchronisée')
                        ->body("Commande #{$this->record->id} envoyée sur Shopify avec succès.")
                        ->success()
                        ->duration(5000)
                        ->send();

                } catch (\Throwable $e) {
                    Log::error('=== ERREUR Synchronisation Shopify ===');
                    Log::error('Order ID: ' . $this->record->id);
                    Log::error('Message: ' . $e->getMessage());
                    Log::error('Trace: ' . $e->getTraceAsString());
                    
                    Notification::make()
                        ->title('⚠️ Commande créée (sync échouée)')
                        ->body("Commande #{$this->record->id} créée mais non synchronisée avec Shopify. Erreur: {$e->getMessage()}")
                        ->warning()
                        ->duration(10000)
                        ->send();
                }
            } else {
                Log::info('Synchronisation non demandée');
                
                Notification::make()
                    ->title('✅ Commande créée')
                    ->body("Commande #{$this->record->id} créée avec succès (non synchronisée).")
                    ->success()
                    ->duration(5000)
                    ->send();
            }

            Log::info('=== FIN afterCreate (SUCCESS) ===');

        } catch (\Throwable $e) {
            Log::error('=== ERREUR dans afterCreate ===');
            Log::error('Message: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            
            Notification::make()
                ->title('Erreur après création')
                ->body('Une erreur est survenue après la création: ' . $e->getMessage())
                ->danger()
                ->duration(10000)
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        $shopId = request()->integer('shop_id');
        $shop = Shop::find($shopId);

        Log::info('Chargement du formulaire de création', [
            'shop_id' => $shopId,
            'shop_found' => $shop ? true : false
        ]);

        if (!$shop) {
            Notification::make()
                ->title('Erreur')
                ->body('Aucun magasin sélectionné. Veuillez retourner à la liste et sélectionner un magasin.')
                ->danger()
                ->persistent()
                ->send();
        }
        
        return $form
            ->schema([
                // Champ caché pour shop_id
                Hidden::make('shop_id')
                    ->default($shopId)
                    ->required()
                    ->dehydrated(),
                
                Grid::make(2)
                    ->schema([
                        Fieldset::make('Informations client')
                            ->schema([
                                TextInput::make('customer_name')
                                    ->label('Nom du client')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Jean Dupont'),
                                    
                                TextInput::make('customer_email')
                                    ->label('Email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('jean.dupont@example.com'),
                                    
                                TextInput::make('customer_phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->maxLength(255)
                                    ->placeholder('+33 6 12 34 56 78'),
                                
                                Select::make('currency')
                                    ->label('Devise')
                                    ->options([
                                        'EUR' => 'EUR - Euro',
                                        'USD' => 'USD - Dollar US',
                                        'GBP' => 'GBP - Livre sterling',
                                        'CHF' => 'CHF - Franc suisse',
                                        'CAD' => 'CAD - Dollar canadien',
                                        'AUD' => 'AUD - Dollar australien',
                                        'JPY' => 'JPY - Yen japonais',
                                    ])
                                    ->default('EUR')
                                    ->required()
                                    ->searchable()
                                    ->native(false)
                                    ->live(),
                            ])
                            ->columnSpan(1),
                            
                        Fieldset::make('Adresse de livraison')
                            ->schema([
                                TextInput::make('shipping_address.address1')
                                    ->label('Adresse')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('123 Rue de la Paix'),
                                    
                                TextInput::make('shipping_address.city')
                                    ->label('Ville')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Paris'),
                                    
                                TextInput::make('shipping_address.zip')
                                    ->label('Code postal')
                                    ->required()
                                    ->maxLength(20)
                                    ->placeholder('75001'),
                                    
                                TextInput::make('shipping_address.country')
                                    ->label('Pays')
                                    ->default('France')
                                    ->required()
                                    ->maxLength(255),
                        ])
                            ->columnSpan(1),
                    ]),
                
                Fieldset::make('Promotion')
                    ->schema([
                        TextInput::make('promo_code')
                            ->label('Code promo')
                            ->maxLength(255),
                        TextInput::make('promo_discount_percentage')
                            ->label('Réduction (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->helperText('Appliquée au total des articles si un code promo est renseigné.'),
                    ])
                    ->columns(2),
                
                Fieldset::make('Articles de la commande')
                    ->schema([
                        Repeater::make('items')
                            ->label('Articles')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Produit')
                                    ->options(
                                        $shop 
                                            ? Product::where('shop_id', $shop->id)->pluck('title', 'id')
                                            : []
                                    )
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state && $product = Product::find($state)) {
                                            $set('unit_price', $product->price);
                                            $set('shopify_variant_id', $product->shopify_variant_id);
                                            
                                            Log::info('Produit sélectionné dans le formulaire:', [
                                                'product_id' => $product->id,
                                                'name' => $product->title ?? $product->name,
                                                'price' => $product->price
                                            ]);
                                        }
                                    })
                                    ->placeholder('Sélectionner un produit'),
                                    
                                TextInput::make('quantity')
                                    ->label('Quantité')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required()
                                    ->live(),
                                    
                                TextInput::make('unit_price')
                                    ->label('Prix unitaire')
                                    ->numeric()
                                    ->required()
                                    ->prefix(fn (Get $get) => $get('../../currency') ?? 'EUR')
                                    ->step(0.01)
                                    ->disabled(fn(Get $get) => (bool) $get('product_id')),
                                    
                                Hidden::make('shopify_variant_id')
                                    ->dehydrated(),
                            ])
                            ->columns(3)
                            ->itemLabel(fn (array $state): ?string => 
                                isset($state['product_id']) 
                                    ? Product::find($state['product_id'])?->title 
                                    : 'Nouvel article'
                            )
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('+ Ajouter un article')
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        
                        Toggle::make('sync_now')
                            ->label('Synchroniser immédiatement avec Shopify')
                            ->helperText('La commande sera envoyée sur Shopify juste après sa création')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Ne pas rediriger après création : on reste sur la même page
     */
    protected function shouldRedirect(): bool
    {
        return false;
    }

    protected function getRedirectUrl(): string
    {
        // Ne sera pas utilisé car shouldRedirect() retourne false,
        // on le laisse uniquement pour compatibilité éventuelle.
        return $this->getResource()::getUrl('create');
    }

    protected function getCreatedNotification(): ?Notification
    {
        // Désactiver la notification par défaut car on gère nos propres notifications
        return null;
    }
}
