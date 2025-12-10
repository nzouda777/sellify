<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Shop;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create')
                ->label('Nouvelle commande')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    Select::make('shop_id')
                        ->label('Sélectionnez une boutique')
                        ->options(Shop::query()->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Choisissez la boutique pour laquelle vous voulez créer une commande')
                        ->placeholder('Sélectionner une boutique'),
                ])
                ->modalHeading('Créer une nouvelle commande')
                ->modalDescription('Sélectionnez d\'abord la boutique pour laquelle vous voulez créer cette commande.')
                ->modalSubmitActionLabel('Continuer')
                ->modalWidth('md')
                ->requiresConfirmation(false)
                ->action(function (array $data): void {
                    $shopId = $data['shop_id'];
                    
                    Log::info('Création de commande initiée', [
                        'shop_id' => $shopId,
                        'user' => auth()->id(),
                    ]);

                    $shop = Shop::find($shopId);
                    
                    if (!$shop) {
                        Log::error('Shop non trouvé lors de la création', ['shop_id' => $shopId]);
                        
                        Notification::make()
                            ->title('❌ Erreur')
                            ->body('Boutique introuvable.')
                            ->danger()
                            ->send();
                        
                        return;
                    }

                    // Rediriger vers la page de création avec le shop_id en query string
                    $this->redirect(OrderResource::getUrl('create') . '?shop_id=' . $shopId);
                }),
        ];
    }
}