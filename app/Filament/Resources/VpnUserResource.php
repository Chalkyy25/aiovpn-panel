<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnUserResource\Pages;
use Illuminate\Database\Eloquent\Builder;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VpnUserResource extends Resource
{
    protected static ?string $model = VpnUser::class;

    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'VPN Users';
    protected static ?string $pluralModelLabel = 'VPN Users';
    protected static ?string $navigationGroup = 'VPN';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('VPN User')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('device_name')
                        ->maxLength(255),

                    Forms\Components\Toggle::make('is_active')
                        ->default(true),

                    Forms\Components\Toggle::make('is_trial')
                        ->default(false),

                    Forms\Components\TextInput::make('max_connections')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('0 = unlimited')
                        ->default(1),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->seconds(false)
                        ->native(false),

                    Forms\Components\TextInput::make('wireguard_address')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\DateTimePicker::make('last_seen_at')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Server Assignment')
                ->schema([
                    // Virtual field. We will sync vpn_server_user pivot in the Page hooks.
                    Forms\Components\Select::make('vpn_server_id')
                        ->label('Server')
                        ->options(fn () => VpnServer::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->default(fn (?VpnUser $record) => $record?->vpnServers()->value('vpn_servers.id')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // avoid N+1
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vpnServers']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->toggleable(),

                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('server')
                    ->label('Server')
                    ->getStateUsing(fn (VpnUser $record) => $record->vpnServers->first()?->name ?? '—'),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean(),

                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(function (VpnUser $u) {
                        if (! $u->is_active) return 'Disabled';
                        if ($u->is_expired)  return 'Expired';
                        return 'Active';
                    })
                    ->color(function (string $state) {
                        return match ($state) {
                            'Active'   => 'success',
                            'Expired'  => 'danger',
                            'Disabled' => 'danger',
                            default    => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('connection_summary')
                    ->label('Conn')
                    ->getStateUsing(fn (VpnUser $u) => (string) ($u->connection_summary ?? ''))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_online')->label('Online'),

                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now())),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
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