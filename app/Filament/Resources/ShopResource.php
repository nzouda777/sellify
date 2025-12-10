<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopResource\Pages;
use App\Filament\Resources\ShopResource\RelationManagers;
use App\Models\Shop;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use App\Filament\Resources\OrderResource;

class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('shopify_domain')
                ->label('Shopify domain (myshop.myshopify.com)')
                ->required(),
            Forms\Components\TextInput::make('timezone')->default('UTC'),
            Forms\Components\Toggle::make('auto_sync_enabled'),
            Forms\Components\Select::make('auto_sync_mode')
                ->options([
                    'scheduled' => 'Scheduled',
                    'immediate' => 'Immediate',
                ])
                ->default('scheduled'),
            Forms\Components\TextInput::make('status')->default('connected'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('shopify_domain')->sortable()->searchable(),
                Tables\Columns\IconColumn::make('auto_sync_enabled')->boolean(),
                Tables\Columns\TextColumn::make('timezone'),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('created_at')->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShops::route('/'),
            'create' => Pages\CreateShop::route('/create'),
            'edit' => Pages\EditShop::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SyncWindowsRelationManager::class,
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\ProductsRelationManager::class,
        ];
    }
}
