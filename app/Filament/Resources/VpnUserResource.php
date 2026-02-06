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
                                ->schema([
                                    Forms\Components\TextInput::make('username')
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(table: VpnUser::class, column: 'username', ignoreRecord: true)
                                        ->autofocus()
                                        ->autocomplete(false),

                                    Forms\Components\TextInput::make('device_name')
                                        ->label('Device')
                                        ->maxLength(255)
                                        ->placeholder('e.g. iPhone 15 / Windows Laptop'),

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
                                                        $total,
                                                    ),
                                                ];
                                            })
                                            ->all())
                                        ->default(fn () => Package::query()->where('is_active', true)->orderBy('duration_months')->orderBy('price_credits')->value('id'))
                                        ->native(false)
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (?VpnUser $record) => $record === null)
                                        ->visible(fn (?VpnUser $record) => $record === null) // create-only
                                        ->dehydrated(false) // virtual; enforced in CreateVpnUser
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set): void {
                                            $package = Package::query()->find((int) $state);
                                            if (! $package) {
                                                return;
                                            }

                                            $set('max_connections', (int) $package->max_connections);

                                            $months = (int) $package->duration_months;
                                            $set('expires_at', $months <= 0 ? null : now()->addMonthsNoOverflow($months));
                                        })
                                        ->helperText('Expiry is set from the selected package.'),

                                    // persisted, not user-editable
                                    Forms\Components\Hidden::make('expires_at'),

                                    // edit-only, read-only visibility of current expiry
                                    Forms\Components\Placeholder::make('current_expiry')
                                        ->label('Current expiry')
                                        ->visible(fn (?VpnUser $record) => filled($record))
                                        ->content(fn (?VpnUser $record) => $record?->expires_at?->format('d M Y') ?? 'Never'),

                                    // edit-only: renewal term (mirrors old flow: only changes expiry if expired)
                                    Forms\Components\Select::make('renewal_term_months')
                                        ->label('Renewal term')
                                        ->options([
                                            1 => '1 Month',
                                            3 => '3 Months',
                                            6 => '6 Months',
                                            12 => '12 Months',
                                        ])
                                        ->native(false)
                                        ->dehydrated(false) // virtual
                                        ->visible(fn (?VpnUser $record) => (bool) ($record?->is_expired))
                                        ->helperText('Only shown for expired users. Selecting sets a new expiry from now.')
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set): void {
                                            $months = (int) $state;
                                            if ($months > 0) {
                                                $set('expires_at', now()->addMonthsNoOverflow($months));
                                            }
                                        }),

                                    Forms\Components\TextInput::make('max_connections')
                                        ->label('Max Devices')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(1)
                                        ->helperText('0 = unlimited')
                                        ->default(1),

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
                                        ->dehydrated(false)
                                        ->placeholder('Assigned automatically'),

                                    Forms\Components\DateTimePicker::make('last_seen_at')
                                        ->label('Last Seen')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->seconds(false)
                                        ->native(false)
                                        ->placeholder('Never'),

                                ]),

                            Forms\Components\Section::make('Server Assignment')
                                ->description('Select one or more servers for this user.')
                                ->schema([
                                    Forms\Components\Select::make('vpn_server_ids')
                                        ->label('Servers')
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->dehydrated(false) // virtual; synced manually in the Page classes
                                        ->options(function (): array {
                                            $servers = VpnServer::query()
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all();

                                            return ['__all__' => 'All servers'] + $servers;
                                        })
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set): void {
                                            $state = (array) $state;

                                            if (in_array('__all__', $state, true)) {
                                                $allIds = VpnServer::query()->orderBy('name')->pluck('id')->all();
                                                $set('vpn_server_ids', array_map('intval', $allIds));
                                            }
                                        })
                                        ->helperText('Tip: pick "All servers" to select every server.'),
                                ]),
                        ]),

                    // Create-only summary (similar to old right panel)
                    Forms\Components\Section::make('Summary')
                        ->columnSpan(['default' => 3, 'lg' => 1])
                        ->visible(fn (?VpnUser $record) => $record === null)
                        ->schema([
                            Forms\Components\Placeholder::make('summary_max_connections')
                                ->label('Max connections')
                                ->content(fn (Get $get) => (string) ((int) ($get('max_connections') ?? 0))),

                            Forms\Components\Placeholder::make('summary_expires')
                                ->label('Expires')
                                ->content(function (Get $get): string {
                                    $expires = $get('expires_at');
                                    if (blank($expires)) {
                                        return 'Never';
                                    }

                                    try {
                                        return \Carbon\Carbon::parse($expires)->format('d M Y');
                                    } catch (\Throwable) {
                                        return (string) $expires;
                                    }
                                }),

                            Forms\Components\Placeholder::make('summary_total_cost')
                                ->label('Total cost')
                                ->content(function (Get $get): string {
                                    $packageId = (int) ($get('package_id') ?? 0);
                                    if ($packageId <= 0) {
                                        return '—';
                                    }

                                    $p = Package::query()->find($packageId);
                                    if (! $p) {
                                        return '—';
                                    }

                                    $months = (int) $p->duration_months;
                                    $total = $months * (int) $p->price_credits;

                                    return $total . ' credits';
                                }),

                            Forms\Components\Placeholder::make('summary_balance')
                                ->label(fn () => (auth()->user()?->role ?? null) === 'admin' ? 'Current credits' : 'Balance after')
                                ->content(function (Get $get): string {
                                    $user = auth()->user();
                                    $credits = $user->credits ?? null; // only if your User model has this

                                    $packageId = (int) ($get('package_id') ?? 0);
                                    $p = $packageId > 0 ? Package::query()->find($packageId) : null;

                                    $months = (int) ($p->duration_months ?? 0);
                                    $total = $months * (int) ($p->price_credits ?? 0);

                                    if (! is_numeric($credits)) {
                                        return '—';
                                    }

                                    if (($user->role ?? null) === 'admin') {
                                        return (string) ((int) $credits);
                                    }

                                    return (string) ((int) $credits - (int) $total);
                                }),

                            Forms\Components\Placeholder::make('summary_warning')
                                ->label('')
                                ->content(function (Get $get): string {
                                    $user = auth()->user();
                                    if (($user->role ?? null) === 'admin') {
                                        return 'Admin — price shown, not deducted.';
                                    }

                                    $credits = $user->credits ?? null;
                                    if (! is_numeric($credits)) {
                                        return '';
                                    }

                                    $packageId = (int) ($get('package_id') ?? 0);
                                    $p = $packageId > 0 ? Package::query()->find($packageId) : null;

                                    $months = (int) ($p->duration_months ?? 0);
                                    $total = $months * (int) ($p->price_credits ?? 0);

                                    if ((int) $credits < (int) $total) {
                                        return 'Not enough credits for this purchase.';
                                    }

                                    return '';
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
                    ->copyMessage('Username copied')
                    ->limit(30),

                Tables\Columns\TagsColumn::make('vpnServers.name')
                    ->label('Servers')
                    ->limitList(5)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->alignCenter()
                    ->state(function (VpnUser $u): string {
                        if (! $u->is_active) {
                            return 'Disabled';
                        }

                        if ($u->is_expired) {
                            return 'Expired';
                        }

                        if ($u->is_trial) {
                            return 'Trial';
                        }

                        return 'Active';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active'   => 'success',
                        'Trial'    => 'warning',
                        'Expired'  => 'danger',
                        'Disabled' => 'danger',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('connection_summary')
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

                Tables\Filters\SelectFilter::make('vpnServers')
                    ->label('Server')
                    ->relationship('vpnServers', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
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