<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use App\Filament\Resources\OrderResource;
use App\Services\Shopify\ShopifyProductService;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('syncProductsAll')
                ->label('Sync produits (toutes boutiques)')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->action(function () {
                    $shops = $this->getTableQuery()->get();
                    $total = 0;

                    foreach ($shops as $shop) {
                        $products = app(ShopifyProductService::class)->syncProducts($shop);
                        $total += $products->count();
                    }

                    if ($total > 0) {
                        Notification::make()
                            ->title('Sync produits terminée')
                            ->body("{$total} produits importés au total.")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Sync produits')
                            ->body("Aucun produit importé. Vérifie les tokens Shopify et les logs.")
                            ->warning()
                            ->send();
                    }
                }),
            Actions\Action::make('connectShopify')
                ->label('Connect Shopify')
                ->color('primary')
                ->icon('heroicon-o-link')
                ->form([
                    Forms\Components\TextInput::make('shop')
                        ->label('Shop domain')
                        ->placeholder('myshop.myshopify.com')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $shop = trim($data['shop']);

                    return redirect()->to(url('/shopify/connect?shop=' . $shop));
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('createOrder')
                ->label('Créer une vente')
                ->icon('heroicon-o-shopping-cart')
                ->url(fn ($record) => OrderResource::getUrl('create', ['shop_id' => $record->id])),
            Tables\Actions\Action::make('syncProducts')
                ->label('Sync produits')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->action(function ($record) {
                    $products = app(ShopifyProductService::class)->syncProducts($record);

                    if ($products->isNotEmpty()) {
                        Notification::make()
                            ->title('Produits synchronisés')
                            ->body("{$products->count()} produits importés pour {$record->name}.")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Sync produits')
                            ->body("Aucun produit n'a été importé. Vérifie le token ou les logs.")
                            ->warning()
                            ->send();
                    }
                }),
        ];
    }
}
