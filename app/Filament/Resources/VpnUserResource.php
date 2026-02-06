<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnUserResource\Pages;
use App\Models\User;
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
            Forms\Components\Section::make('VPN User')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->required()
                        ->maxLength(255)
                        ->autocapitalize('none')
                        ->autocomplete('off'),

                    Forms\Components\TextInput::make('device_name')
                        ->maxLength(255),

                    Forms\Components\Select::make('client_id')
                        ->label('Owner (Client)')
                        ->options(fn () => User::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false),

                    Forms\Components\TextInput::make('max_connections')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('0 = unlimited')
                        ->default(1),

                    Forms\Components\Toggle::make('is_active')->default(true),
                    Forms\Components\Toggle::make('is_trial')->default(false),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->seconds(false)
                        ->native(false),

                    Forms\Components\TextInput::make('last_ip')
                        ->label('Last IP')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\DateTimePicker::make('last_seen_at')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Server Assignment')
                ->schema([
                    // ✅ this is the correct way: uses your belongsToMany(vpnServers) and auto-syncs pivot
                    Forms\Components\Select::make('vpnServers')
                        ->label('Assigned Servers')
                        ->relationship('vpnServers', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->native(false)
                        ->helperText('Select one or more servers (pivot vpn_server_user will auto-sync).'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vpnServers', 'client']))
            ->defaultSort('id', 'desc')
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Copied'),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Owner')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                Tables\Columns\TagsColumn::make('vpnServers.name')
                    ->label('Servers')
                    ->separator(',')
                    ->limitList(3) // keeps it clean on mobile
                    ->expandable(),

                Tables\Columns\BadgeColumn::make('is_online')
                    ->label('Online')
                    ->formatStateUsing(fn (bool $v) => $v ? 'Online' : 'Offline')
                    ->colors([
                        'success' => true,
                        'danger' => false,
                    ]),

                Tables\Columns\BadgeColumn::make('state')
                    ->label('State')
                    ->getStateUsing(function (VpnUser $u) {
                        if (! $u->is_active) return 'Disabled';
                        if ($u->is_expired)  return 'Expired';
                        return 'Active';
                    })
                    ->colors([
                        'success' => 'Active',
                        'danger'  => ['Expired', 'Disabled'],
                    ]),

                Tables\Columns\TextColumn::make('connection_summary')
                    ->label('Conn')
                    ->getStateUsing(fn (VpnUser $u) => (string) ($u->connection_summary ?? ''))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_online')->label('Online'),

                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                    ),
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