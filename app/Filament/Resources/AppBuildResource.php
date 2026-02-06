<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppBuildResource\Pages;
use App\Filament\Resources\AppBuildResource\RelationManagers;
use App\Models\AppBuild;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AppBuildResource extends Resource
{
    protected static ?string $model = AppBuild::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('version_code')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('version_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('apk_path')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('sha256')
                    ->required()
                    ->maxLength(64),
                Forms\Components\Toggle::make('mandatory')
                    ->required(),
                Forms\Components\Textarea::make('release_notes')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('version_code')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('version_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('apk_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sha256')
                    ->searchable(),
                Tables\Columns\IconColumn::make('mandatory')
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
            'index' => Pages\ListAppBuilds::route('/'),
            'create' => Pages\CreateAppBuild::route('/create'),
            'edit' => Pages\EditAppBuild::route('/{record}/edit'),
        ];
    }
}
