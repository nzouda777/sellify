<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Commandes';
    protected static ?string $pluralModelLabel = 'Commandes';
    protected static ?string $modelLabel = 'Commande';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informations de la boutique')
                ->schema([
                    Forms\Components\Select::make('shop_id')
                        ->label('Boutique')
                        ->relationship('shop', 'name')
                        ->required()
                        ->live()
                        ->searchable()
                        ->preload()
                        ->default(fn () => request()->integer('shop_id'))
                        ->afterStateUpdated(function ($state) {
                            Log::info('Shop sélectionné:', ['shop_id' => $state]);
                        })
                        ->helperText('Sélectionnez d\'abord une boutique pour charger ses produits'),
                ])
                ->collapsible(),

            Forms\Components\Section::make('Informations client')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('customer_name')
                                ->label('Nom du client')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Jean Dupont'),
                            
                            Forms\Components\TextInput::make('customer_email')
                                ->label('Email')
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->placeholder('jean.dupont@example.com'),
                            
                            Forms\Components\TextInput::make('customer_phone')
                                ->label('Téléphone')
                                ->tel()
                                ->maxLength(255)
                                ->placeholder('+33 6 12 34 56 78'),
                            
                            Forms\Components\TextInput::make('currency')
                                ->label('Devise')
                                ->default('EUR')
                                ->maxLength(3)
                                ->placeholder('EUR'),
                        ]),
                ])
                ->collapsible(),

            Forms\Components\Section::make('Adresse de livraison')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('shipping_address.address1')
                                ->label('Adresse')
                                ->maxLength(255)
                                ->placeholder('123 Rue de la Paix')
                                ->columnSpan(2),
                            
                            Forms\Components\TextInput::make('shipping_address.city')
                                ->label('Ville')
                                ->maxLength(255)
                                ->placeholder('Paris'),
                            
                            Forms\Components\TextInput::make('shipping_address.province')
                                ->label('État/Province')
                                ->maxLength(255)
                                ->placeholder('Île-de-France'),
                            
                            Forms\Components\TextInput::make('shipping_address.zip')
                                ->label('Code postal')
                                ->maxLength(20)
                                ->placeholder('75001'),
                            
                            Forms\Components\TextInput::make('shipping_address.country')
                                ->label('Pays')
                                ->maxLength(100)
                                ->default('France')
                                ->placeholder('France'),
                        ]),
                ])
                ->collapsible(),

            Forms\Components\Section::make('Articles de la commande')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->label('Articles')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Produit')
                                ->options(function (Forms\Get $get) {
                                    // Récupérer le shop_id depuis le formulaire parent
                                    $shopId = $get('../../shop_id');
                                    
                                    Log::info('Chargement des produits pour repeater', [
                                        'shop_id' => $shopId,
                                        'context' => 'repeater'
                                    ]);

                                    if (!$shopId) {
                                        Log::warning('Aucun shop_id trouvé dans le repeater');
                                        return [];
                                    }

                                    $products = Product::query()
                                        ->where('shop_id', $shopId)
                                        ->orderBy('title')
                                        ->get()
                                        ->mapWithKeys(function (Product $product) {
                                            $label = $product->title ?? $product->name ?? 'Produit sans nom';
                                            
                                            if (!empty($product->variant_title)) {
                                                $label .= " ({$product->variant_title})";
                                            }
                                            
                                            if ($product->price !== null) {
                                                $label .= ' - ' . number_format($product->price, 2) . ' €';
                                            }
                                            
                                            return [$product->id => $label];
                                        })
                                        ->toArray();

                                    Log::info('Produits chargés:', ['count' => count($products)]);
                                    
                                    return $products;
                                })
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->disabled(fn (Forms\Get $get) => blank($get('../../shop_id')))
                                ->helperText('Sélectionnez d\'abord une boutique')
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    if (!$state) {
                                        return;
                                    }

                                    $product = Product::find($state);
                                    
                                    if (!$product) {
                                        Log::warning('Produit non trouvé:', ['product_id' => $state]);
                                        return;
                                    }

                                    Log::info('Produit sélectionné dans repeater:', [
                                        'product_id' => $product->id,
                                        'name' => $product->title ?? $product->name,
                                        'price' => $product->price,
                                        'shopify_variant_id' => $product->shopify_variant_id
                                    ]);

                                    $price = $product->price ?? 0;
                                    $set('unit_price', $price);
                                    $set('shopify_variant_id', $product->shopify_variant_id);
                                    
                                    $qty = $get('quantity') ?? 1;
                                    $total = $qty * $price;
                                    $set('total_price', $total);

                                    Log::info('Prix calculé:', [
                                        'quantity' => $qty,
                                        'unit_price' => $price,
                                        'total_price' => $total
                                    ]);
                                })
                                ->placeholder('Sélectionner un produit'),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Quantité')
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $price = $get('unit_price') ?? 0;
                                    $qty = $state ?? 1;
                                    $total = $qty * $price;
                                    $set('total_price', $total);

                                    Log::info('Quantité mise à jour:', [
                                        'quantity' => $qty,
                                        'unit_price' => $price,
                                        'total_price' => $total
                                    ]);
                                }),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Prix unitaire')
                                ->numeric()
                                ->prefix('€')
                                ->step(0.01)
                                ->required()
                                ->live()
                                ->helperText('Prix par défaut du produit')
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    $qty = $get('quantity') ?? 1;
                                    $total = $qty * ($state ?? 0);
                                    $set('total_price', $total);
                                }),

                            Forms\Components\TextInput::make('total_price')
                                ->label('Total')
                                ->numeric()
                                ->prefix('€')
                                ->disabled()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (Forms\Get $get, Forms\Set $set) {
                                    $qty = $get('quantity') ?? 1;
                                    $price = $get('unit_price') ?? 0;
                                    $set('total_price', $qty * $price);
                                }),

                            Forms\Components\Hidden::make('shopify_variant_id')
                                ->dehydrated(),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => 
                            isset($state['product_id']) 
                                ? (Product::find($state['product_id'])?->title ?? Product::find($state['product_id'])?->name ?? 'Article')
                                : 'Nouvel article'
                        )
                        ->addActionLabel('+ Ajouter un article')
                        ->live()
                        ->afterStateUpdated(function ($state) {
                            Log::info('Items mis à jour:', ['items_count' => count($state ?? [])]);
                        }),
                ])
                ->collapsible(),

            Forms\Components\Section::make('Options')
                ->schema([
                    Forms\Components\Toggle::make('sync_now')
                        ->label('Synchroniser immédiatement avec Shopify')
                        ->helperText('La commande sera envoyée sur Shopify juste après sa création')
                        ->default(true)
                        ->inline(false)
                        ->dehydrated(false),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('shop.name')
                    ->label('Boutique')
                    ->sortable()
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('customer_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('eur')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Articles')
                    ->sortable()
                    ->toggleable(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                        'secondary' => 'processing',
                    ])
                    ->sortable(),
                    
                Tables\Columns\BadgeColumn::make('sync_status')
                    ->label('Sync Shopify')
                    ->colors([
                        'danger' => 'not_synced',
                        'warning' => 'syncing',
                        'success' => 'synced',
                        'secondary' => 'failed',
                    ])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('shopify_order_id')
                    ->label('ID Shopify')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifiée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('shop')
                    ->relationship('shop', 'name')
                    ->label('Boutique'),
                    
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Complétée',
                        'cancelled' => 'Annulée',
                    ]),
                    
                Tables\Filters\SelectFilter::make('sync_status')
                    ->label('Statut Sync')
                    ->options([
                        'not_synced' => 'Non synchronisée',
                        'syncing' => 'En cours',
                        'synced' => 'Synchronisée',
                        'failed' => 'Échouée',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Voir'),
                    
                Tables\Actions\EditAction::make()
                    ->label('Modifier'),
                    
                Tables\Actions\Action::make('sync')
                    ->label('Sync Shopify')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Synchroniser avec Shopify')
                    ->modalDescription('Voulez-vous synchroniser cette commande avec Shopify ?')
                    ->modalSubmitActionLabel('Synchroniser')
                    ->action(function (Order $record) {
                        try {
                            Log::info('Action manuelle de sync', ['order_id' => $record->id]);
                            
                            app(OrderSyncService::class)->syncToShopify($record);
                            
                            Log::info('Sync manuelle réussie', ['order_id' => $record->id]);
                            
                            Notification::make()
                                ->title('✅ Synchronisation réussie')
                                ->body("Commande #{$record->id} envoyée sur Shopify.")
                                ->success()
                                ->duration(5000)
                                ->send();
                                
                        } catch (\Throwable $e) {
                            Log::error('Erreur sync manuelle', [
                                'order_id' => $record->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            
                            Notification::make()
                                ->title('❌ Synchronisation échouée')
                                ->body($e->getMessage())
                                ->danger()
                                ->duration(10000)
                                ->send();
                        }
                    })
                    ->visible(fn (Order $record) => 
                        !in_array($record->sync_status, ['synced', 'syncing'])
                    ),
                    
                Tables\Actions\DeleteAction::make()
                    ->label('Supprimer'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Supprimer la sélection'),
                        
                    Tables\Actions\BulkAction::make('sync_bulk')
                        ->label('Synchroniser avec Shopify')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $success = 0;
                            $failed = 0;
                            
                            foreach ($records as $record) {
                                try {
                                    app(OrderSyncService::class)->syncToShopify($record);
                                    $success++;
                                } catch (\Throwable $e) {
                                    Log::error('Bulk sync error', [
                                        'order_id' => $record->id,
                                        'error' => $e->getMessage()
                                    ]);
                                    $failed++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Synchronisation terminée')
                                ->body("Réussies: {$success}, Échouées: {$failed}")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('sync_status', 'not_synced')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}