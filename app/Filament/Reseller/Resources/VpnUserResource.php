<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\VpnUserResource\Pages;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VpnUserResource extends Resource
{
    protected static ?string $model = VpnUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'VPN Users';
    protected static ?string $navigationGroup = 'VPN';
    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('client_id', auth()->id())
            ->with('vpnServers');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('VPN Account')
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->required()
                        ->maxLength(50)
                        ->helperText('Must be unique. You can leave it blank and auto-generate later if you want, but for now: required.'),

                    Forms\Components\TextInput::make('plain_password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->helperText('If left blank, the system auto-generates one.')
                        ->dehydrated(true),

                    Forms\Components\TextInput::make('device_name')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('max_connections')
                        ->numeric()
                        ->minValue(0)
                        ->default(1)
                        ->helperText('0 = unlimited'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Access & Expiry')
                ->schema([
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Expiry date')
                        ->seconds(false)
                        ->helperText('Leave empty for no expiry.'),

                    Forms\Components\Toggle::make('is_active')
                        ->default(true),

                    Forms\Components\Toggle::make('is_trial')
                        ->default(false),
                ])
                ->columns(3),

            Forms\Components\Section::make('Assigned Servers')
                ->schema([
                    Forms\Components\Select::make('vpnServers')
                        ->label('Servers')
                        ->relationship('vpnServers', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('This controls where this user can connect.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TagsColumn::make('vpnServers.name')
                    ->label('Servers')
                    ->limitList(2),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (VpnUser $record) =>
                        $record->expires_at && $record->expires_at->isPast()
                            ? 'danger'
                            : 'success'
                    ),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_trial')
                    ->label('Trial')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),

                Tables\Filters\SelectFilter::make('server')
                    ->label('Server')
                    ->relationship('vpnServers', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVpnUsers::route('/'),
            'create' => Pages\CreateVpnUser::route('/create'),
            'edit'   => Pages\EditVpnUser::route('/{record}/edit'),
        ];
    }
}