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
use Illuminate\Database\Eloquent\Builder;

class VpnServerResource extends Resource
{
    protected static ?string $model = VpnServer::class;

    protected static ?string $navigationGroup = 'VPN';
    protected static ?string $navigationLabel = 'Servers';
    protected static ?string $navigationIcon  = 'heroicon-o-server-stack';
    protected static ?int $navigationSort     = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Tabs::make('Server')
                ->columnSpanFull()
                ->tabs([

                    /*
                    |--------------------------------------------------------------------------
                    | BASIC
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('Basic')
                        ->schema([

                            Forms\Components\Section::make('Server Details')
                                ->columns(2)
                                ->schema([

                                    Forms\Components\TextInput::make('name')
                                        ->required()
                                        ->maxLength(255),

                                    Forms\Components\TextInput::make('ip_address')
                                        ->label('IP Address')
                                        ->required(),

                                    Forms\Components\TextInput::make('location')
                                        ->placeholder('London, UK'),

                                    Forms\Components\TextInput::make('city'),

                                    Forms\Components\TextInput::make('country_code')
                                        ->maxLength(2),

                                    Forms\Components\Select::make('protocol')
                                        ->options([
                                            'wireguard' => 'WireGuard',
                                            'openvpn'   => 'OpenVPN',
                                        ])
                                        ->default('wireguard')
                                        ->required(),

                                    Forms\Components\Toggle::make('enable_logging')
                                        ->default(true),

                                    Forms\Components\Toggle::make('enable_ipv6')
                                        ->default(false),

                                ]),
                        ]),

                    /*
                    |--------------------------------------------------------------------------
                    | SSH
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('SSH')
                        ->schema([

                            Forms\Components\Section::make('SSH Access')
                                ->columns(2)
                                ->schema([

                                    Forms\Components\Select::make('ssh_type')
                                        ->options([
                                            'key'      => 'SSH Key',
                                            'password' => 'Password',
                                        ])
                                        ->default('key')
                                        ->required(),

                                    Forms\Components\TextInput::make('ssh_user')
                                        ->default('root')
                                        ->required(),

                                    Forms\Components\TextInput::make('ssh_port')
                                        ->numeric()
                                        ->default(22)
                                        ->required(),

                                    Forms\Components\Select::make('deploy_key_id')
                                        ->relationship('deployKey', 'name')
                                        ->searchable()
                                        ->preload()
                                        ->visible(fn ($get) => $get('ssh_type') === 'key'),

                                    Forms\Components\TextInput::make('ssh_password')
                                        ->password()
                                        ->revealable()
                                        ->visible(fn ($get) => $get('ssh_type') === 'password'),

                                ]),
                        ]),

                    /*
                    |--------------------------------------------------------------------------
                    | WIREGUARD
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('WireGuard')
                        ->schema([

                            Forms\Components\Section::make('WireGuard Configuration')
                                ->columns(2)
                                ->schema([

                                    Forms\Components\TextInput::make('wg_port')
                                        ->numeric()
                                        ->default(51820),

                                    Forms\Components\TextInput::make('wg_subnet')
                                        ->default('10.66.66.0/24'),

                                    Forms\Components\TextInput::make('wg_endpoint_host')
                                        ->columnSpanFull(),

                                    Forms\Components\Textarea::make('wg_public_key')
                                        ->rows(3)
                                        ->columnSpanFull()
                                        ->disabled(),

                                ]),
                        ]),

                    /*
                    |--------------------------------------------------------------------------
                    | MONITORING
                    |--------------------------------------------------------------------------
                    */

                    Forms\Components\Tabs\Tab::make('Monitoring')
                        ->schema([

                            Forms\Components\Section::make('Server Status')
                                ->columns(2)
                                ->schema([

                                    Forms\Components\Placeholder::make('deployment_status')
                                        ->label('Deployment Status')
                                        ->content(fn ($record) =>
                                            $record?->deployment_status ?? 'pending'
                                        ),

                                    Forms\Components\Placeholder::make('status')
                                        ->label('Online Status')
                                        ->content(fn ($record) =>
                                            $record?->is_online
                                                ? 'ONLINE'
                                                : 'OFFLINE'
                                        ),

                                    Forms\Components\Placeholder::make('online_users')
                                        ->content(fn ($record) =>
                                            $record?->online_users ?? 0
                                        ),

                                    Forms\Components\Placeholder::make('last_sync_at')
                                        ->content(fn ($record) =>
                                            $record?->last_sync_at?->diffForHumans() ?? 'Never'
                                        ),

                                ]),

                            Forms\Components\Section::make('Deployment Log')
                                ->collapsed()
                                ->schema([

                                    Forms\Components\Textarea::make('deployment_log')
                                        ->rows(14)
                                        ->disabled()
                                        ->columnSpanFull(),

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

                Tables\Columns\TextColumn::make('location')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->color(fn (string $state) =>
                        match ($state) {
                            'wireguard' => 'success',
                            'openvpn'   => 'warning',
                            default     => 'gray',
                        }
                    ),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->label('Status')
                    ->formatStateUsing(fn ($record) =>
                        $record->is_online ? 'ONLINE' : 'OFFLINE'
                    )
                    ->colors([
                        'success' => fn ($record) => $record->is_online,
                        'danger'  => fn ($record) => ! $record->is_online,
                    ]),

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Users')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Heartbeat')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->copyable()
                    ->toggleable(),

            ])

            ->filters([

                Tables\Filters\SelectFilter::make('protocol')
                    ->options([
                        'wireguard' => 'WireGuard',
                        'openvpn'   => 'OpenVPN',
                    ]),

                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Online'),

            ])

            ->actions([

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('deploy')
                    ->label('Deploy')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (VpnServer $record): void {

                        $record->update([
                            'deployment_status' => 'queued',
                        ]);

                        DeployVpnServer::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Deployment queued')
                            ->body("{$record->name} queued successfully.")
                            ->send();
                    }),

                Tables\Actions\Action::make('logs')
                    ->label('Logs')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (VpnServer $record) =>
                        "Deployment Logs — {$record->name}"
                    )
                    ->modalSubmitAction(false)
                    ->modalContent(fn (VpnServer $record) =>
                        view('filament.modals.server-deployment-log', [
                            'serverId' => $record->id,
                        ])
                    ),

            ])

            ->bulkActions([

                Tables\Actions\BulkActionGroup::make([

                    Tables\Actions\DeleteBulkAction::make(),

                ]),

            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVpnServers::route('/'),
            'create' => Pages\CreateVpnServer::route('/create'),
            'edit'   => Pages\EditVpnServer::route('/{record}/edit'),
        ];
    }
}