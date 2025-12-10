<?php

namespace App\Filament\Resources\ShopResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class SyncWindowsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncWindows';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label'),
            Forms\Components\TimePicker::make('window_start_time')->seconds(false)->required(),
            Forms\Components\TimePicker::make('window_end_time')->seconds(false)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label'),
                Tables\Columns\TextColumn::make('window_start_time'),
                Tables\Columns\TextColumn::make('window_end_time'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
