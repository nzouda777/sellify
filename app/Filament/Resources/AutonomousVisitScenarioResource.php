<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AutonomousVisitScenarioResource\Pages;
use App\Models\AutonomousVisitScenario;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Carbon\CarbonInterface;

class AutonomousVisitScenarioResource extends Resource
{
    protected static ?string $model = AutonomousVisitScenario::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Scénarios de visites';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('shop_id')
                ->relationship('shop', 'name')
                ->required(),
            Forms\Components\TextInput::make('name')
                ->label('Nom du scénario')
                ->required(),
            Forms\Components\TextInput::make('target_url')
                ->label('URL cible')
                ->required()
                ->url(),
            Forms\Components\TimePicker::make('window_start_time')
                ->label('Début de la fenêtre')
                ->seconds(false),
            Forms\Components\TimePicker::make('window_end_time')
                ->label('Fin de la fenêtre')
                ->seconds(false),
            Forms\Components\TextInput::make('min_interval_seconds')
                ->label('Intervalle min (secondes)')
                ->numeric()
                ->default(60),
            Forms\Components\TextInput::make('max_interval_seconds')
                ->label('Intervalle max (secondes)')
                ->numeric()
                ->default(300),
            Forms\Components\TextInput::make('target_visits')
                ->label('Nombre total de visites (one-shot)')
                ->numeric()
                ->minValue(1)
                ->nullable(),
            Forms\Components\TextInput::make('generated_visits')
                ->label('Visites déjà générées')
                ->disabled()
                ->dehydrated(false),
            Forms\Components\Toggle::make('is_active')
                ->label('Scénario actif ?')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Scénario')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shop.name')
                    ->label('Boutique'),
                Tables\Columns\TextColumn::make('target_url')
                    ->label('URL cible')
                    ->limit(40),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Progression')
                    ->getStateUsing(function (AutonomousVisitScenario $record): string {
                        if (!$record->target_visits) {
                            return $record->generated_visits . ' / ∞';
                        }

                        return $record->generated_visits . ' / ' . $record->target_visits;
                    }),
                Tables\Columns\TextColumn::make('remaining')
                    ->label('Restantes')
                    ->getStateUsing(function (AutonomousVisitScenario $record): string {
                        if (!$record->target_visits) {
                            return '∞';
                        }

                        return (string) max($record->target_visits - $record->generated_visits, 0);
                    }),
                Tables\Columns\TextColumn::make('next_delay')
                    ->label('Prochaine dans')
                    ->getStateUsing(function (AutonomousVisitScenario $record): string {
                        if (!$record->next_run_at) {
                            return '-';
                        }

                        return now()->diffForHumans($record->next_run_at, [
                            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                            'short' => true,
                        ]);
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif ?')
                    ->boolean(),
                Tables\Columns\TextColumn::make('next_run_at')
                    ->label('Prochaine exécution')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Supprimer la sélection')
                        ->requiresConfirmation()
                        ->modalHeading('Supprimer les scénarios de visites sélectionnés')
                        ->modalSubheading('Cette action est définitive. Les scénarios de visites autonomes sélectionnés seront supprimés.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutonomousVisitScenarios::route('/'),
            'create' => Pages\CreateAutonomousVisitScenario::route('/create'),
            'edit' => Pages\EditAutonomousVisitScenario::route('/{record}/edit'),
        ];
    }
}
