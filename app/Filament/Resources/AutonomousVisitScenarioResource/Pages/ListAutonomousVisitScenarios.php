<?php

namespace App\Filament\Resources\AutonomousVisitScenarioResource\Pages;

use App\Filament\Resources\AutonomousVisitScenarioResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListAutonomousVisitScenarios extends ListRecords
{
    protected static string $resource = AutonomousVisitScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Créer un scénario de visites'),
            Actions\Action::make('sync_autonomous_visits')
                ->label('Lancer les scénarios de visites maintenant')
                ->icon('heroicon-m-play')
                ->requiresConfirmation()
                ->action(function (): void {
                    Artisan::call('scenarios:run-autonomous-visits', [
                        '--limit' => 50,
                    ]);

                    Notification::make()
                        ->title('Scénarios de visites lancés')
                        ->body('Les scénarios actifs ont été scannés et les visites à générer ont été enfilées.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
