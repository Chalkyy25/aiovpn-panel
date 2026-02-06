<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
// use App\Filament\Resources\PackageResource\RelationManagers; // unused
use App\Models\Package;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
// use Illuminate\Database\Eloquent\Builder; // unused
// use Illuminate\Database\Eloquent\SoftDeletingScope; // unused

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price_credits')
                    ->label('Price (credits per month)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                Forms\Components\TextInput::make('max_connections')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(1)
                    ->default(1)
                    ->helperText('0 = unlimited'),
                Forms\Components\TextInput::make('duration_months')
                    ->label('Duration (months)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(1)
                    ->default(1)
                    ->helperText('0 = no expiry'),
                Forms\Components\Toggle::make('is_featured')
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_credits')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_connections')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_months')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
