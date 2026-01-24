<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Services\OrderSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Synchroniser avec Shopify')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Synchroniser avec Shopify')
                ->modalDescription('Voulez-vous synchroniser cette commande avec Shopify ?')
                ->modalSubmitActionLabel('Synchroniser')
                ->action(function (): void {
                    try {
                        Log::info('Sync depuis EditOrder', ['order_id' => $this->record->id]);
                        
                        App::make(OrderSyncService::class)->syncToShopify($this->record);
                        
                        Log::info('Sync réussie depuis EditOrder', ['order_id' => $this->record->id]);
                        
                        Notification::make()
                            ->title('✅ Synchronisation réussie')
                            ->body("Commande #{$this->record->id} envoyée sur Shopify.")
                            ->success()
                            ->duration(5000)
                            ->send();
                            
                    } catch (\Throwable $e) {
                        Log::error('Erreur sync depuis EditOrder', [
                            'order_id' => $this->record->id,
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
                ->visible(fn () => 
                    !in_array($this->record->sync_status, ['synced', 'syncing'])
                ),
                
            Actions\DeleteAction::make()
                ->label('Supprimer'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('✅ Commande mise à jour')
            ->body("Commande #{$this->record->id} modifiée avec succès.")
            ->success()
            ->duration(3000);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            Log::info('=== DEBUT mutateFormDataBeforeSave (Edit) ===');
            Log::info('Données reçues pour modification:', array_keys($data));

            // Recalculer les totaux si les items ont changé
            $discountAmount = 0;
            $discountPercent = isset($data['promo_discount_percentage'])
                ? max(0, min(100, (float) $data['promo_discount_percentage']))
                : 0;

            if (isset($data['items']) && is_array($data['items'])) {
                $totalAmount = 0;
                $totalQuantity = 0;

                foreach ($data['items'] as &$item) {
                    $lineTotal = floatval($item['unit_price'] ?? 0) * intval($item['quantity'] ?? 0);
                    $item['total_price'] = $lineTotal;
                    $totalAmount += $lineTotal;
                    $totalQuantity += intval($item['quantity'] ?? 0);
                }

                if (!empty($data['promo_code']) && $discountPercent > 0) {
                    $discountAmount = round($totalAmount * ($discountPercent / 100), 2);
                    $totalAmount = max($totalAmount - $discountAmount, 0);
                }

                $data['amount'] = round($totalAmount, 2);
                $data['quantity'] = $totalQuantity;
                Log::info('Totaux recalculés:', [
                    'amount' => $totalAmount,
                    'quantity' => $totalQuantity,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                ]);
            }

            $data['promo_discount_percentage'] = $discountPercent;

            // Mise à jour du payload si l'adresse a changé
            $currentPayload = $this->record->payload ?? [];

            if (isset($data['shipping_address'])) {
                $currentPayload['shipping_address'] = $data['shipping_address'];
                unset($data['shipping_address']);
            }

            $currentPayload['discount'] = [
                'code' => $data['promo_code'] ?? $this->record->promo_code,
                'percent' => $discountPercent,
                'amount' => $discountAmount,
            ];

            if (!empty($currentPayload)) {
                $data['payload'] = $currentPayload;
            }

            Log::info('=== FIN mutateFormDataBeforeSave (Edit) ===');

            return $data;

        } catch (\Throwable $e) {
            Log::error('=== ERREUR dans mutateFormDataBeforeSave (Edit) ===');
            Log::error('Message: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            
            Notification::make()
                ->title('Erreur de mise à jour')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }
}
