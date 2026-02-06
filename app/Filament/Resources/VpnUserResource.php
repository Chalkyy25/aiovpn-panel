<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnUserResource\Pages;
use App\Models\Package;
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
                            ->mapWithKeys(fn (Package $p) => [
                                $p->id => sprintf(
                                    '%s — %s mo — %s device%s — %s credits',
                                    $p->name,
                                    (int) $p->duration_months,
                                    (int) $p->max_connections,
                                    ((int) $p->max_connections) === 1 ? '' : 's',
                                    (int) $p->price_credits,
                                ),
                            ])
                            ->all())
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->visible(fn (?VpnUser $record) => $record === null) // create-only
                        ->dehydrated(false) // virtual; applied in CreateVpnUser
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $packageId = (int) $state;
                            if ($packageId <= 0) {
                                return;
                            }

                            $package = Package::query()->find($packageId);
                            if (! $package) {
                                return;
                            }

                            $set('max_connections', (int) $package->max_connections);

                            $months = (int) $package->duration_months;
                            $set('expires_at', $months <= 0 ? null : now()->addMonthsNoOverflow($months));
                        })
                        ->helperText('Sets Max Devices and Expiry from the selected package.'),

                    Forms\Components\TextInput::make('max_connections')
                        ->label('Max Devices')
                        ->numeric()
                        ->minValue(0)
                        ->step(1)
                        ->helperText('0 = unlimited')
                        ->default(1),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Expiry')
                        ->seconds(false)
                        ->native(false)
                        ->placeholder('No expiry'),

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

                    Forms\Components\Select::make('package_length_days')
                        ->label('Package length')
                        ->options([
                            7 => '7 days',
                            30 => '30 days',
                            90 => '90 days',
                            180 => '180 days',
                            365 => '365 days',
                            0 => 'No expiry',
                        ])
                        ->default(30)
                        ->native(false)
                        ->searchable()
                        ->helperText('Selecting a package sets the Expiry field automatically.')
                        ->dehydrated(false) // virtual field
                        ->visible(fn (?VpnUser $record) => $record === null) // create-only
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $days = (int) $state;

                            $set('expires_at', $days === 0 ? null : now()->addDays($days));
                        }),
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