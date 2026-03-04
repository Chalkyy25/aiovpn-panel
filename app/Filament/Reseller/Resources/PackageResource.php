<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Packages';
    protected static ?string $navigationGroup = null;
    protected static ?int    $navigationSort  = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Resellers should only see active packages.
        return parent::getEloquentQuery()
            ->where('is_active', true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('price_credits')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Package')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('description')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(80)
                    ->wrap(),

                Tables\Columns\TextColumn::make('price_credits')
                    ->label('Credits')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Devices')
                    ->state(fn (Package $p) => (int) $p->max_connections === 0 ? 'Unlimited' : (string) $p->max_connections)
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_months')
                    ->label('Duration')
                    ->state(fn (Package $p) => (int) $p->duration_months === 0 ? 'No expiry' : ((int) $p->duration_months . ' month(s)'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
        ];
    }
}
