<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AutonomousScenarioResource\Pages;
use App\Models\AutonomousScenario;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AutonomousScenarioResource extends Resource
{
    protected static ?string $model = AutonomousScenario::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Automation';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('shop_id')
                ->relationship('shop', 'name')
                ->required(),
            Forms\Components\TextInput::make('name')
                ->label('Nom du scénario')
                ->required(),
            Forms\Components\TextInput::make('location_label')
                ->label('Label de localisation')
                ->helperText('Utilisé pour choisir la locale Faker automatiquement'),
            Forms\Components\Select::make('faker_locale')
                ->label('Locale Faker')
                ->options([
                    'en_US' => 'US English',
                    'fr_FR' => 'French',
                    'en_GB' => 'UK English',
                    'de_DE' => 'German',
                ])
                ->default('en_US'),
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
            Forms\Components\TextInput::make('min_quantity')
                ->label('Quantité min par commande')
                ->numeric()
                ->default(1),
            Forms\Components\TextInput::make('max_quantity')
                ->label('Quantité max par commande')
                ->numeric()
                ->default(1),
            Forms\Components\TextInput::make('min_amount')
                ->label('Montant min')
                ->numeric()
                ->prefix('$'),
            Forms\Components\TextInput::make('max_amount')
                ->label('Montant max')
                ->numeric()
                ->prefix('$'),
            Forms\Components\TextInput::make('fulfill_orders')
                ->label('Nombre total de commandes a marquer livré')
                ->numeric()
                ->minValue(1)
                ->required(),
            Forms\Components\TextInput::make('target_orders')
                ->label('Nombre total de commandes (one-shot)')
                ->numeric()
                ->minValue(1)
                ->required(),
            Forms\Components\TextInput::make('promo_code')
                ->label('Code promo'),
            Forms\Components\TextInput::make('promo_discount_percentage')
                ->label('Réduction (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->suffix('%')
                ->helperText('Pourcentage appliqué si un code promo est renseigné.'),
            Forms\Components\TextInput::make('generated_orders')
                ->label('Commandes déjà générées')
                ->disabled()
                ->dehydrated(false),
            Forms\Components\Toggle::make('is_active')
                ->label('Scénario actif ?'),
            Forms\Components\MultiSelect::make('products')
                ->label('Produits cibles')
                ->relationship('products', 'title')
                ->required()
                ->helperText('Produits de la boutique utilisés pour générer les commandes automatiques'),
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
                Tables\Columns\TextColumn::make('location_label')
                    ->label('Localisation'),
                Tables\Columns\TextColumn::make('faker_locale')
                    ->label('Locale Faker'),
                Tables\Columns\TextColumn::make('promo_code')
                    ->label('Code promo')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('promo_discount_percentage')
                    ->label('Réduction')
                    ->formatStateUsing(fn ($state) => $state ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.') . '%' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Progression')
                    ->getStateUsing(function (AutonomousScenario $record): string {
                        if (!$record->target_orders) {
                            return $record->generated_orders . ' / ∞';
                        }

                        return $record->generated_orders.' / '.$record->target_orders;
                    }),
                Tables\Columns\TextColumn::make('remaining')
                    ->label('Restantes')
                    ->getStateUsing(function (AutonomousScenario $record): string {
                        if (!$record->target_orders) {
                            return '∞';
                        }

                        return (string) max($record->target_orders - $record->generated_orders, 0);
                    }),
                Tables\Columns\TextColumn::make('next_delay')
                    ->label('Prochaine dans')
                    ->getStateUsing(function (AutonomousScenario $record): string {
                        if (!$record->next_run_at) {
                            return '-';
                        }

                        return now()->diffForHumans($record->next_run_at, [
                            'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
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
                        ->modalHeading('Supprimer les scénarios sélectionnés')
                        ->modalSubheading('Cette action est définitive. Les scénarios autonomes sélectionnés seront supprimés. '),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutonomousScenarios::route('/'),
            'create' => Pages\CreateAutonomousScenario::route('/create'),
            'edit' => Pages\EditAutonomousScenario::route('/{record}/edit'),
        ];
    }
}
