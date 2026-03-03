<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\VpnUserResource\Pages;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VpnUserResource extends Resource
{
    protected static ?string $model = VpnUser::class;

    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'VPN Users';
    protected static ?string $navigationGroup = 'VPN';
    protected static ?int    $navigationSort  = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('client_id', auth()->id())
            ->with('vpnServers');
    }

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
                                ->schema([
                                    Forms\Components\TextInput::make('username')
                                        ->maxLength(50)
                                        ->unique(table: VpnUser::class, column: 'username', ignoreRecord: true)
                                        ->helperText('Leave blank to auto-generate.'),

                                    Forms\Components\TextInput::make('plain_password')
                                        ->label('Password')
                                        ->password()
                                        ->revealable()
                                        ->helperText('Leave blank to auto-generate.')
                                        ->dehydrated(true),

                                    Forms\Components\TextInput::make('device_name')
                                        ->label('Device')
                                        ->maxLength(100)
                                        ->placeholder('e.g. iPhone / Firestick'),

                                    // CREATE ONLY (virtual) → sets max_connections + expires_at
                                    Forms\Components\Select::make('package_id')
                                        ->label('Package')
                                        ->options(fn (): array => Package::query()
                                            ->where('is_active', true)
                                            ->orderBy('duration_months')
                                            ->orderBy('price_credits')
                                            ->get()
                                            ->mapWithKeys(function (Package $p): array {
                                                $months = (int) $p->duration_months;
                                                $dev    = (int) $p->max_connections;
                                                $total  = $months * (int) $p->price_credits;

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
                                        ->dehydrated(false)
                                        ->live()
                                        ->afterStateUpdated(function ($state, Set $set): void {
                                            $package = Package::query()->find((int) $state);
                                            if (! $package) {
                                                return;
                                            }

                                            $set('max_connections', (int) $package->max_connections);

                                            $months = (int) $package->duration_months;
                                            $set('expires_at', $months <= 0 ? null : now()->addMonthsNoOverflow($months));
                                        }),

                                    Forms\Components\TextInput::make('max_connections')
                                        ->label('Max Devices')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(1)
                                        ->helperText('0 = unlimited'),

                                    Forms\Components\Hidden::make('expires_at'),

                                    Forms\Components\Toggle::make('is_active')
                                        ->label('Active')
                                        ->default(true),

                                    Forms\Components\Toggle::make('is_trial')
                                        ->label('Trial')
                                        ->default(false),
                                ]),

                            Forms\Components\Section::make('Servers')
                                ->schema([
                                    Forms\Components\Toggle::make('all_servers')
                                        ->label('Assign to all servers')
                                        ->dehydrated(false)
                                        ->live()
                                        ->afterStateUpdated(function (bool $state, Set $set): void {
                                            if (! $state) {
                                                return;
                                            }

                                            $set(
                                                'vpn_server_ids',
                                                VpnServer::query()
                                                    ->orderBy('name')
                                                    ->pluck('id')
                                                    ->map(fn ($id) => (int) $id)
                                                    ->all()
                                            );
                                        }),

                                    // Virtual field; persisted via Pages hooks (syncVpnServers)
                                    Forms\Components\Select::make('vpn_server_ids')
                                        ->label('Selected servers')
                                        ->multiple()
                                        ->preload()
                                        ->searchable()
                                        ->native(false)
                                        ->required()
                                        ->dehydrated(false)
                                        ->options(fn (): array => VpnServer::query()->orderBy('name')->pluck('name', 'id')->all())
                                        ->visible(fn (Get $get) => ! (bool) $get('all_servers'))
                                        ->helperText('Controls which servers this user can connect to.'),
                                ]),
                        ]),

                    Forms\Components\Section::make('Summary')
                        ->columnSpan(['default' => 3, 'lg' => 1])
                        ->schema([
                            Forms\Components\Placeholder::make('expires_preview')
                                ->label('Expires')
                                ->content(function (Get $get): string {
                                    $expires = $get('expires_at');
                                    if (blank($expires)) return 'Never';

                                    try {
                                        return \Carbon\Carbon::parse($expires)->format('d M Y');
                                    } catch (\Throwable) {
                                        return (string) $expires;
                                    }
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
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->copyable(),

                Tables\Columns\TextColumn::make('plain_password')
                    ->label('Password')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Password copied')
                    ->state(fn (VpnUser $u) => $u->plain_password ?: '******')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('servers')
                    ->label('Servers')
                    ->state(fn (VpnUser $u) => $u->vpnServers->pluck('name')->values()->all())
                    ->badge()
                    ->separator(', ')
                    ->wrap(),

                Tables\Columns\TextColumn::make('connection_summary')
                    ->label('Conn')
                    ->badge()
                    ->color('gray')
                    ->state(fn (VpnUser $u) => (string) ($u->connection_summary ?? '0/0'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max Devices')
                    ->badge()
                    ->color('gray')
                    ->state(fn (VpnUser $u) => ((int) $u->max_connections === 0) ? 'Unlimited' : (string) (int) $u->max_connections)
                    ->alignCenter()
                    ->toggleable(),

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
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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