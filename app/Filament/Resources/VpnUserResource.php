<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnUserResource\Pages;
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
    protected static ?string $pluralModelLabel = 'VPN Users';
    protected static ?string $navigationGroup = 'VPN';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->required()
                        ->maxLength(255)
                        ->autofocus(),

                    Forms\Components\TextInput::make('device_name')
                        ->label('Device')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('max_connections')
                        ->label('Max Devices')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('0 = unlimited')
                        ->default(1),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Expiry')
                        ->seconds(false)
                        ->native(false),

                    Forms\Components\Toggle::make('is_active')
                        ->inline(false)
                        ->default(true),

                    Forms\Components\Toggle::make('is_trial')
                        ->inline(false)
                        ->default(false),

                    Forms\Components\TextInput::make('wireguard_address')
                        ->label('WG Address')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\DateTimePicker::make('last_seen_at')
                        ->label('Last Seen')
                        ->disabled()
                        ->dehydrated(false)
                        ->seconds(false)
                        ->native(false),
                ]),

            Forms\Components\Section::make('Server Assignment')
                ->description('Assign this user to a VPN server (we sync the pivot table on save).')
                ->schema([
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vpnServers']))
            ->defaultSort('id', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->size('sm')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Username copied')
                    ->limit(24),

                Tables\Columns\TextColumn::make('server')
                    ->label('Server')
                    ->badge()
                    ->color('info')
                    ->getStateUsing(fn (VpnUser $u) => $u->vpnServers->first()?->name ?? 'Unassigned')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        // Sort by server name via pivot relation (best-effort)
                        return $query->orderBy(
                            VpnServer::select('name')
                                ->join('vpn_server_user', 'vpn_server_user.vpn_server_id', '=', 'vpn_servers.id')
                                ->whereColumn('vpn_server_user.vpn_user_id', 'vpn_users.id')
                                ->limit(1),
                            $direction
                        );
                    }),

                Tables\Columns\TextColumn::make('online_status')
                    ->label('Online')
                    ->badge()
                    ->alignCenter()
                    ->state(fn (VpnUser $u) => $u->is_online ? 'Online' : 'Offline')
                    ->color(fn (string $state) => $state === 'Online' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->alignCenter()
                    ->state(function (VpnUser $u) {
                        if (! $u->is_active) return 'Disabled';
                        if ($u->is_expired)  return 'Expired';
                        if ($u->is_trial)    return 'Trial';
                        return 'Active';
                    })
                    ->color(function (string $state) {
                        return match ($state) {
                            'Active'   => 'success',
                            'Trial'    => 'warning',
                            'Expired'  => 'danger',
                            'Disabled' => 'danger',
                            default    => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('connections')
                    ->label('Conn')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->state(fn (VpnUser $u) => (string) ($u->connection_summary ?? '0/0'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expiry')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_online')->label('Online'),
                Tables\Filters\TernaryFilter::make('is_trial')->label('Trial'),

                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now())),

                Tables\Filters\SelectFilter::make('server')
                    ->label('Server')
                    ->options(fn () => VpnServer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data) {
                        $serverId = $data['value'] ?? null;
                        if (! $serverId) return $query;

                        return $query->whereHas('vpnServers', fn (Builder $q) => $q->where('vpn_servers.id', $serverId));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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