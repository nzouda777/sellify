<?php

namespace App\Filament\Resources\AutonomousScenarioResource\Pages;

use App\Filament\Resources\AutonomousScenarioResource;
use App\Models\AutonomousScenario;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListAutonomousScenarios extends ListRecords
{
    protected static string $resource = AutonomousScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Créer un scénario'),
            Actions\Action::make('sync_autonomous_orders')
                ->label('Lancer les scénarios maintenant')
                ->icon('heroicon-m-play')
                ->requiresConfirmation()
                ->action(function (): void {
                    // Lancer la commande artisan qui enfile les jobs GenerateAutonomousOrder
                    Artisan::call('scenarios:run-autonomous', [
                        '--limit' => 50,
                    ]);

                    Notification::make()
                        ->title('Scénarios autonomes lancés')
                        ->body('Les scénarios actifs ont été scannés et les commandes à générer ont été enfilées.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
