<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiTokenResource\Pages;
use App\Models\ApiToken;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Integration';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('tokenable_type')->label('Owner type'),
                Tables\Columns\TextColumn::make('tokenable_id')->label('Owner ID'),
                Tables\Columns\TextColumn::make('last_used_at')->since(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiTokens::route('/'),
        ];
    }
}
