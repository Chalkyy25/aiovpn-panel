<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $pluralModelLabel = 'Users';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\Select::make('role')
                        ->required()
                        ->options([
                            'admin' => 'Admin',
                            'reseller' => 'Reseller',
                            'client' => 'Client',
                        ])
                        ->native(false),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),

                    Forms\Components\TextInput::make('credits')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ])
                ->columns(2),

            Forms\Components\Section::make('Security')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank to keep the current password.')
                        ->dehydrated(fn ($state) => filled($state))
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                        ->required(fn (string $context) => $context === 'create'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('role')
                    ->sortable()
                    ->colors([
                        'danger' => 'admin',
                        'warning' => 'reseller',
                        'success' => 'client',
                    ]),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('credits')
                    ->sortable()
                    ->alignRight(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'reseller' => 'Reseller',
                        'client' => 'Client',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}