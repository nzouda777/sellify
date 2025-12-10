<?php

namespace App\Filament\Resources\ShopResource\RelationManagers;

use App\Models\Product;
use App\Services\OrderSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';
    protected static ?string $recordTitleAttribute = 'id';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Customer name')->required(),
            Forms\Components\TextInput::make('email')->email(),
            Forms\Components\TextInput::make('currency')->default('USD')->maxLength(3),
            Forms\Components\Fieldset::make('Shipping')
                ->schema([
                    Forms\Components\TextInput::make('shipping_address.address1')->label('Address')->maxLength(255),
                    Forms\Components\TextInput::make('shipping_address.city')->label('City')->maxLength(255),
                    Forms\Components\TextInput::make('shipping_address.province')->label('State/Province')->maxLength(255),
                    Forms\Components\TextInput::make('shipping_address.zip')->label('ZIP/Postal')->maxLength(20),
                    Forms\Components\TextInput::make('shipping_address.country')->label('Country')->maxLength(100)->default('US'),
                ])
                ->columns(2),
            Forms\Components\Repeater::make('items')
                ->relationship()
                ->schema([
                    Forms\Components\Select::make('product_id')
                        ->label('Product (variant)')
                        ->options(fn () => Product::query()
                            ->where('shop_id', $this->ownerRecord->id)
                            ->get()
                            ->mapWithKeys(function (Product $product) {
                                $label = $product->title;
                                if ($product->variant_title) {
                                    $label .= " ({$product->variant_title})";
                                }
                                if ($product->price !== null) {
                                    $label .= ' - $' . $product->price;
                                }
                                return [$product->id => $label];
                            })
                            ->toArray())
                        ->searchable()
                        ->reactive()
                        ->required()
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            if (!$state) {
                                return;
                            }
                            $product = Product::find($state);
                            $price = $product?->price ?? 0;
                            $set('unit_price', $price);
                            $qty = $get('quantity') ?? 1;
                            $set('total_price', $qty * $price);
                        }),
                    Forms\Components\TextInput::make('quantity')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->reactive()
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            $price = $get('unit_price') ?? 0;
                            $qty = $state ?? 1;
                            $set('total_price', $qty * $price);
                        }),
                    Forms\Components\TextInput::make('unit_price')
                        ->numeric()
                        ->prefix('$')
                        ->helperText('Defaults to variant price')
                        ->reactive()
                        ->afterStateUpdated(fn ($state, Set $set, Get $get) => $set('total_price', ($get('quantity') ?? 1) * ($state ?? 0))),
                    Forms\Components\TextInput::make('total_price')
                        ->numeric()
                        ->prefix('$')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            $qty = $get('quantity') ?? 1;
                            $price = $get('unit_price') ?? 0;
                            $set('total_price', $qty * $price);
                        }),
                ])
                ->columns(3)
                ->minItems(1)
                ->createItemButtonLabel('Add product'),
            Forms\Components\Toggle::make('sync_now')
                ->label('Sync to Shopify right after creation')
                ->default(true)
                ->dehydrated(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Customer'),
                Tables\Columns\TextColumn::make('amount')->money('usd'),
                Tables\Columns\BadgeColumn::make('status'),
                Tables\Columns\BadgeColumn::make('sync_status'),
                Tables\Columns\TextColumn::make('created_at')->since(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->successNotificationTitle('Commande créée')
                    ->using(function (array $data) {
                        $data['shop_id'] = $this->ownerRecord->id;
                        $totalAmount = 0;
                        $totalQuantity = 0;

                        $items = $data['items'] ?? [];

                        if (empty($items)) {
                            throw ValidationException::withMessages([
                                'items' => 'Ajoute au moins un produit.',
                            ]);
                        }

                        foreach ($items as &$item) {
                            $product = Product::find($item['product_id']);
                            $item['shopify_variant_id'] = $product?->shopify_variant_id;
                            $item['unit_price'] = $item['unit_price'] ?? $product?->price;
                            $lineTotal = ($item['unit_price'] ?? 0) * $item['quantity'];
                            $item['total_price'] = $lineTotal;
                            $totalAmount += $lineTotal;
                            $totalQuantity += $item['quantity'];
                        }

                        $data['amount'] = $totalAmount;
                        $data['quantity'] = $totalQuantity;
                        $data['status'] = $data['status'] ?? 'pending';
                        $data['sync_status'] = 'not_synced';
                        $data['payload'] = array_merge($data['payload'] ?? [], [
                            'shipping_address' => $data['shipping_address'] ?? [],
                        ]);

                        $syncNow = $data['sync_now'] ?? false;
                        unset($data['items'], $data['sync_now'], $data['shipping_address']);

                        $order = $this->getRelationship()->getRelated()->create($data);
                        $order->items()->createMany($items);

                        // store flag for after hook
                        $order->sync_now_flag = $syncNow;

                        return $order;
                    })
                    ->after(function ($record, $data) {
                        $shouldSync = $record->sync_now_flag ?? ($data['sync_now'] ?? false);
                        unset($record->sync_now_flag);

                        if ($shouldSync) {
                            try {
                                app(OrderSyncService::class)->syncToShopify($record->load('items'));
                                Notification::make()
                                    ->title('Commande synchronisée')
                                    ->body('La commande a été envoyée sur Shopify.')
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Log::error('Sync Shopify error', [
                                    'order_id' => $record->id,
                                    'message' => $e->getMessage(),
                                    'shop_id' => $record->shop_id,
                                ]);
                                Notification::make()
                                    ->title('Sync Shopify échouée')
                                    ->body('La commande est créée mais la synchronisation a échoué. Vérifie les logs.')
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            Notification::make()
                                ->title('Commande créée')
                                ->body('Créée localement (non synchronisée).')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn ($record) => app(OrderSyncService::class)->syncToShopify($record))
                    ->visible(fn ($record) => $record->sync_status !== 'synced'),
            ]);
    }
}
