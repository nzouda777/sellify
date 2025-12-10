<?php

namespace App\Filament\Resources\AutonomousVisitScenarioResource\Pages;

use App\Filament\Resources\AutonomousVisitScenarioResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAutonomousVisitScenario extends EditRecord
{
    protected static string $resource = AutonomousVisitScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
