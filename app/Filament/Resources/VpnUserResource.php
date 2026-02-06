<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnUserResource\Pages;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
            Forms\Components\Grid::make()
                ->columns(3)
                ->schema([
                    Forms\Components\Group::make()
                        ->columnSpan(['default' => 3, 'lg' => 2])
                        ->schema([
                            Forms\Components\Section::make('Account')
                                ->columns(2)
                                ->compact()
                                ->collapsible()
                                ->schema([
                                    Forms\Components\TextInput::make('username')
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(table: VpnUser::class, column: 'username', ignoreRecord: true)
                                        ->autofocus(),

                                    Forms\Components\TextInput::make('device_name')
                                        ->label('Device')
                                        ->maxLength(255)
                                        ->placeholder('e.g. iPhone / Windows'),

                                    // CREATE ONLY – controls expiry + max_connections server-side
                                    Forms\Components\Select::make('package_id')
                                        ->label('Package')
                                        ->options(fn (): array => Package::query()
                                            ->where('is_active', true)
                                            ->orderBy('duration_months')
                                            ->orderBy('price_credits')
                                            ->get()
                                            ->mapWithKeys(function (Package $p): array {
                                                $months = (int) $p->duration_months;
                                                $dev = (int) $p->max_connections;
                                                $total = $months * (int) $p->price_credits;

                                                return [
                                                    $p->id => sprintf(
                                                        '%s — %d month%s — %s device%s — %d credits',
                                                        $p->name,
                                                        $months,
                                                        $months === 1 ? '' : 's',
                                                        $dev === 0 ? 'Unlimited' : (string) $dev,
                                                        $dev === 1 ? '' : 's',
                                                        $total
                                                    ),
                                                ];
                                            })
                                            ->all())
                                        ->native(false)
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (?VpnUser $record) => $record === null)
                                        ->visible(fn (?VpnUser $record) => $record === null)
                                        ->dehydrated(false) // keep virtual
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set): void {
                                            $package = Package::query()->find((int) $state);
                                            if (! $package) return;

                                            $set('max_connections', (int) $package->max_connections);

                                            $months = (int) $package->duration_months;
                                            $set('expires_at', $months <= 0 ? null : now()->addMonthsNoOverflow($months));
                                        }),

                                    // actual persisted fields
                                    Forms\Components\TextInput::make('max_connections')
                                        ->label('Max Devices')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(1)
                                        ->helperText('0 = unlimited')
                                        ->default(1),

                                    Forms\Components\Hidden::make('expires_at'),

                                    Forms\Components\Toggle::make('is_active')
                                        ->label('Active')
                                        ->inline(false)
                                        ->default(true),

                                    Forms\Components\Toggle::make('is_trial')
                                        ->label('Trial')
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

                            Forms\Components\Section::make('Servers')
                                ->compact()
                                ->collapsible()
                                ->schema([
                                    // Toggle “All servers”
                                    Forms\Components\Toggle::make('all_servers')
                                        ->label('Assign to all servers')
                                        ->dehydrated(false)
                                        ->live()
                                        ->afterStateUpdated(function (bool $state, callable $set): void {
                                            if ($state) {
                                                $set('vpn_server_ids', VpnServer::query()->orderBy('name')->pluck('id')->map(fn ($id) => (int) $id)->all());
                                            }
                                        }),

                                    Forms\Components\Select::make('vpn_server_ids')
                                        ->label('Selected servers')
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->dehydrated(false) // virtual; synced in Pages
                                        ->options(fn (): array => VpnServer::query()->orderBy('name')->pluck('name', 'id')->all())
                                        ->visible(fn (Get $get) => ! (bool) $get('all_servers')),
                                ]),
                        ]),

                    Forms\Components\Section::make('Summary')
                        ->compact()
                        ->collapsible()
                        ->visible(fn (?VpnUser $record) => $record === null)
                        ->schema([
                            Forms\Components\Placeholder::make('summary_expires')
                                ->label('Expires')
                                ->content(function (Get $get): string {
                                    $expires = $get('expires_at');
                                    if (blank($expires)) return 'Never';
                                    try { return \Carbon\Carbon::parse($expires)->format('d M Y'); }
                                    catch (\Throwable) { return (string) $expires; }
                                }),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vpnServers'))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
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
                    ->copyMessage('Username copied'),

                Tables\Columns\TextColumn::make('plain_password')
                    ->label('Password')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Password copied')
                    ->state(fn (VpnUser $u) => $u->plain_password ?: '******')
                    ->toggleable(isToggledHiddenByDefault: true),

                // SHOW ALL SERVERS (not 1)
                Tables\Columns\TextColumn::make('servers')
                    ->label('Servers')
                    ->state(fn (VpnUser $u) => $u->vpnServers->pluck('name')->values()->all())
                    ->badge()
                    ->separator(', ')
                    ->wrap(),

                Tables\Columns\TextColumn::make('online_status')
                    ->label('Online')
                    ->badge()
                    ->state(fn (VpnUser $u) => $u->is_online ? 'Online' : 'Offline')
                    ->color(fn (string $state) => $state === 'Online' ? 'success' : 'danger')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(function (VpnUser $u): string {
                        if (! $u->is_active) return 'Disabled';
                        if ($u->is_expired) return 'Expired';
                        if ($u->is_trial) return 'Trial';
                        return 'Active';
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Active' => 'success',
                        'Trial' => 'warning',
                        'Expired', 'Disabled' => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('connection_summary')
                    ->label('Conn')
                    ->badge()
                    ->color('gray')
                    ->state(fn (VpnUser $u) => (string) ($u->connection_summary ?? '0/0'))
                    ->alignCenter(),

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
                Tables\Filters\SelectFilter::make('vpnServers')
                    ->label('Server')
                    ->relationship('vpnServers', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
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