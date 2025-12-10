<?php

namespace App\Filament\Resources\ShopResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';
    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Produit')->searchable(),
                Tables\Columns\TextColumn::make('variant_title')->label('Variante'),
                Tables\Columns\TextColumn::make('sku')->toggleable(),
                Tables\Columns\TextColumn::make('price')->label('Prix')->money('usd'),
                Tables\Columns\BadgeColumn::make('status')->label('Statut'),
            ])
            ->actions([])
            ->emptyStateHeading('Aucun produit')
            ->emptyStateDescription('Clique sur "Sync produits" pour importer les produits Shopify.');
    }
}
