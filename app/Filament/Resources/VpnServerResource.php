<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnServerResource\Pages;
use App\Jobs\DeployVpnServer;
use App\Models\VpnServer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VpnServerResource extends Resource
{
    protected static ?string $model = VpnServer::class;

protected static ?string $navigationGroup = 'VPN';
protected static ?string $navigationLabel = 'Servers';
protected static ?string $navigationIcon  = 'heroicon-o-server';
protected static ?int $navigationSort     = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Server')
                ->columnSpanFull()
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Basics')->schema([
                        Forms\Components\Section::make('Identity')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                                Forms\Components\TextInput::make('ip_address')->label('IP Address')->required()->maxLength(255),

                                Forms\Components\Select::make('protocol')
                                    ->options([
                                        'openvpn' => 'OpenVPN',
                                        'wireguard' => 'WireGuard',
                                    ])
                                    ->required()
                                    ->default('openvpn'),

                                Forms\Components\Select::make('transport')
                                    ->options(['udp' => 'UDP', 'tcp' => 'TCP'])
                                    ->default('udp')
                                    ->visible(fn ($get) => $get('protocol') === 'openvpn'),

                                Forms\Components\TextInput::make('location')->maxLength(255),
                                Forms\Components\TextInput::make('region')->maxLength(255),
                                Forms\Components\TextInput::make('country_code')->maxLength(2),
                                Forms\Components\TextInput::make('city')->maxLength(80),

                                Forms\Components\TextInput::make('port')
                                    ->label('VPN Port')
                                    ->numeric()
                                    ->default(1194)
                                    ->visible(fn ($get) => $get('protocol') === 'openvpn'),

                                Forms\Components\TextInput::make('wg_port')
                                    ->label('WG Port')
                                    ->numeric()
                                    ->default(51820)
                                    ->visible(fn ($get) => $get('protocol') === 'wireguard'),

                                Forms\Components\TextInput::make('wg_subnet')
                                    ->label('WG Subnet')
                                    ->default('10.66.66.0/24')
                                    ->visible(fn ($get) => $get('protocol') === 'wireguard'),
                            ]),

                        Forms\Components\Section::make('Flags')
                            ->columns(3)
                            ->schema([
                                Forms\Components\Toggle::make('enable_ipv6'),
                                Forms\Components\Toggle::make('enable_logging'),
                                Forms\Components\Toggle::make('enable_proxy'),
                                Forms\Components\Toggle::make('header1'),
                                Forms\Components\Toggle::make('header2'),
                            ]),
                    ]),

                    Forms\Components\Tabs\Tab::make('SSH')->schema([
                        Forms\Components\Section::make('SSH Access')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('ssh_type')
                                    ->options(['key' => 'SSH Key', 'password' => 'Password'])
                                    ->default('key')
                                    ->required(),

                                Forms\Components\TextInput::make('ssh_user')->default('root')->required(),
                                Forms\Components\TextInput::make('ssh_port')->numeric()->default(22)->required(),

                                Forms\Components\Select::make('deploy_key_id')
                                    ->relationship('deployKey', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->helperText('Preferred: choose a stored deploy key.'),

                                Forms\Components\TextInput::make('ssh_key')
                                    ->helperText('Legacy: key identifier/path. Prefer Deploy Key.')
                                    ->visible(fn ($get) => $get('ssh_type') === 'key')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('ssh_password')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('ssh_type') === 'password')
                                    ->maxLength(255),
                            ]),
                    ]),

                    Forms\Components\Tabs\Tab::make('WireGuard')->schema([
                        Forms\Components\Section::make('WireGuard Facts')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('wg_endpoint_host')->maxLength(255),
                                Forms\Components\Textarea::make('wg_public_key')->rows(3)->columnSpanFull(),
                                Forms\Components\TextInput::make('wg_private_key')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn ($get) => $get('protocol') === 'wireguard'),
                    ]),

                    Forms\Components\Tabs\Tab::make('Deployment')->schema([
                        Forms\Components\Section::make('Deployment')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('deployment_status')
                                    ->options([
                                        'queued' => 'Queued',
                                        'running' => 'Running',
                                        'success' => 'Success',
                                        'failed' => 'Failed',
                                        'pending' => 'Pending',
                                        'deployed' => 'Deployed',
                                    ])
                                    ->default('queued'),

                                Forms\Components\TextInput::make('status')->default('pending'),

                                Forms\Components\TextInput::make('status_log_path')->maxLength(255),
                                Forms\Components\DateTimePicker::make('last_sync_at'),

                                Forms\Components\TextInput::make('online_users')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Toggle::make('is_online')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),

                        Forms\Components\Section::make('Deployment Log')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Textarea::make('deployment_log')->rows(12)->disabled()->columnSpanFull(),
                            ]),
                    ]),

                    Forms\Components\Tabs\Tab::make('Advanced')->schema([
                        Forms\Components\Section::make('Advanced')
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('mgmt_port')->numeric()->default(7505),
                                Forms\Components\TextInput::make('tcp_mgmt_port')->numeric()->default(7506),
                                Forms\Components\TextInput::make('dns')->maxLength(255),
                                Forms\Components\TextInput::make('tags')
                                    ->helperText('If you store tags as JSON array, enter as JSON or handle via custom UI.'),
                            ]),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('display_location')
                    ->label('Location')
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->copyable()
                    ->copyMessage('Copied')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Users')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deployment_status')
                    ->badge()
                    ->colors([
                        'warning' => 'queued',
                        'info' => 'running',
                        'success' => ['success', 'deployed'],
                        'danger' => 'failed',
                        'gray' => 'pending',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->since()
                    ->label('Last Sync')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->label('Created')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('protocol')
                    ->options([
                        'openvpn' => 'OpenVPN',
                        'wireguard' => 'WireGuard',
                    ]),
                Tables\Filters\TernaryFilter::make('is_online')->label('Online'),
                Tables\Filters\SelectFilter::make('deployment_status')
                    ->options([
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'pending' => 'Pending',
                        'deployed' => 'Deployed',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('deploy')
                    ->label('Deploy')
                    ->icon('heroicon-o-rocket-launch')
                    ->requiresConfirmation()
                    ->disabled(fn (VpnServer $record): bool => (bool) ($record->is_deploying ?? false))
                    ->action(function (VpnServer $record): void {
                        $record->forceFill([
                            'deployment_status' => 'queued',
                            'status' => $record->status ?: 'pending',
                            'is_deploying' => false,
                        ])->save();

                        DeployVpnServer::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Deployment queued')
                            ->body("Server: {$record->name}")
                            ->send();
                    }),

                Tables\Actions\Action::make('log')
                    ->label('Log')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (VpnServer $record): string => "Deployment Log — {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (VpnServer $record) => view('filament.modals.server-deployment-log', [
                        'serverId' => (int) $record->id,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVpnServers::route('/'),
            'create' => Pages\CreateVpnServer::route('/create'),
            'edit' => Pages\EditVpnServer::route('/{record}/edit'),
        ];
    }
}
