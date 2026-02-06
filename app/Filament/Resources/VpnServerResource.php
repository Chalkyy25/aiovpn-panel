<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnServerResource\Pages;
use App\Filament\Resources\VpnServerResource\RelationManagers;
use App\Models\VpnServer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VpnServerResource extends Resource
{
    protected static ?string $model = VpnServer::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ip_address')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ssh_port')
                    ->required()
                    ->numeric()
                    ->default(22),
                Forms\Components\TextInput::make('ssh_user')
                    ->required()
                    ->maxLength(255)
                    ->default('root'),
                Forms\Components\TextInput::make('protocol')
                    ->required()
                    ->maxLength(255)
                    ->default('openvpn'),
                Forms\Components\Toggle::make('supports_openvpn')
                    ->required(),
                Forms\Components\Toggle::make('supports_wireguard')
                    ->required(),
                Forms\Components\TextInput::make('location')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_online')
                    ->required(),
                Forms\Components\TextInput::make('group')
                    ->maxLength(255),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('pending'),
                Forms\Components\TextInput::make('online_users')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\DateTimePicker::make('last_mgmt_at'),
                Forms\Components\DateTimePicker::make('last_sync_at'),
                Forms\Components\TextInput::make('deployment_status')
                    ->required(),
                Forms\Components\Textarea::make('deployment_log')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('status_log_path')
                    ->maxLength(255),
                Forms\Components\TextInput::make('ssh_type')
                    ->maxLength(255),
                Forms\Components\TextInput::make('ssh_password')
                    ->password()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ssh_key')
                    ->maxLength(255),
                Forms\Components\TextInput::make('port')
                    ->numeric(),
                Forms\Components\TextInput::make('mgmt_port')
                    ->required()
                    ->numeric()
                    ->default(7505),
                Forms\Components\TextInput::make('transport')
                    ->maxLength(255),
                Forms\Components\TextInput::make('dns')
                    ->maxLength(255),
                Forms\Components\Toggle::make('enable_ipv6')
                    ->required(),
                Forms\Components\Toggle::make('enable_logging')
                    ->required(),
                Forms\Components\Toggle::make('enable_proxy')
                    ->required(),
                Forms\Components\Toggle::make('header1')
                    ->required(),
                Forms\Components\Toggle::make('header2')
                    ->required(),
                Forms\Components\TextInput::make('provider')
                    ->maxLength(255),
                Forms\Components\TextInput::make('region')
                    ->maxLength(255),
                Forms\Components\TextInput::make('country_code')
                    ->maxLength(2),
                Forms\Components\TextInput::make('city')
                    ->maxLength(80),
                Forms\Components\TextInput::make('tags'),
                Forms\Components\Toggle::make('enabled')
                    ->required(),
                Forms\Components\Toggle::make('ipv6_enabled')
                    ->required(),
                Forms\Components\TextInput::make('mtu')
                    ->numeric(),
                Forms\Components\TextInput::make('api_endpoint')
                    ->maxLength(255),
                Forms\Components\Toggle::make('monitoring_enabled')
                    ->required(),
                Forms\Components\TextInput::make('health_check_cmd')
                    ->maxLength(255),
                Forms\Components\TextInput::make('install_branch')
                    ->maxLength(255),
                Forms\Components\TextInput::make('max_clients')
                    ->numeric(),
                Forms\Components\TextInput::make('rate_limit_mbps')
                    ->numeric(),
                Forms\Components\Toggle::make('allow_split_tunnel')
                    ->required(),
                Forms\Components\TextInput::make('ovpn_cipher')
                    ->maxLength(255),
                Forms\Components\TextInput::make('ovpn_compression')
                    ->maxLength(255),
                Forms\Components\Textarea::make('wg_public_key')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('wg_endpoint_host')
                    ->maxLength(255),
                Forms\Components\TextInput::make('wg_port')
                    ->numeric()
                    ->default(51820),
                Forms\Components\TextInput::make('wg_subnet')
                    ->maxLength(32)
                    ->default('10.66.66.0/24'),
                Forms\Components\Textarea::make('wg_private_key')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('notes')
                    ->maxLength(500),
                Forms\Components\Select::make('deploy_key_id')
                    ->relationship('deployKey', 'name'),
                Forms\Components\TextInput::make('tcp_mgmt_port')
                    ->numeric()
                    ->default(7506),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ssh_port')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ssh_user')
                    ->searchable(),
                Tables\Columns\TextColumn::make('protocol')
                    ->searchable(),
                Tables\Columns\IconColumn::make('supports_openvpn')
                    ->boolean(),
                Tables\Columns\IconColumn::make('supports_wireguard')
                    ->boolean(),
                Tables\Columns\TextColumn::make('location')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_online')
                    ->boolean(),
                Tables\Columns\TextColumn::make('group')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('online_users')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deployment_status'),
                Tables\Columns\TextColumn::make('status_log_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ssh_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ssh_key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('port')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mgmt_port')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('transport')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dns')
                    ->searchable(),
                Tables\Columns\IconColumn::make('enable_ipv6')
                    ->boolean(),
                Tables\Columns\IconColumn::make('enable_logging')
                    ->boolean(),
                Tables\Columns\IconColumn::make('enable_proxy')
                    ->boolean(),
                Tables\Columns\IconColumn::make('header1')
                    ->boolean(),
                Tables\Columns\IconColumn::make('header2')
                    ->boolean(),
                Tables\Columns\TextColumn::make('provider')
                    ->searchable(),
                Tables\Columns\TextColumn::make('region')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean(),
                Tables\Columns\IconColumn::make('ipv6_enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('mtu')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('api_endpoint')
                    ->searchable(),
                Tables\Columns\IconColumn::make('monitoring_enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('health_check_cmd')
                    ->searchable(),
                Tables\Columns\TextColumn::make('install_branch')
                    ->searchable(),
                Tables\Columns\TextColumn::make('max_clients')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_limit_mbps')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('allow_split_tunnel')
                    ->boolean(),
                Tables\Columns\TextColumn::make('ovpn_cipher')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ovpn_compression')
                    ->searchable(),
                Tables\Columns\TextColumn::make('wg_endpoint_host')
                    ->searchable(),
                Tables\Columns\TextColumn::make('wg_port')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('wg_subnet')
                    ->searchable(),
                Tables\Columns\TextColumn::make('notes')
                    ->searchable(),
                Tables\Columns\TextColumn::make('deployKey.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tcp_mgmt_port')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
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
