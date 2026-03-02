<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\VpnUserResource\Pages;
use App\Models\VpnUser;
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
    protected static ?string $navigationGroup = 'VPN';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        // If your Pages define the form, keep this empty.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->weight('medium'),

                // ✅ MANY-TO-MANY: show assigned servers
                Tables\Columns\TagsColumn::make('vpnServers.name')
                    ->label('Servers')
                    ->separator(',')
                    ->limitList(3),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->sortable()
                    ->color(fn (VpnUser $record) =>
                        $record->expires_at && $record->expires_at->isPast()
                            ? 'danger'
                            : 'success'
                    ),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),

                // ✅ Filter by MANY-TO-MANY servers
                Tables\Filters\SelectFilter::make('vpnServers')
                    ->label('Server')
                    ->relationship('vpnServers', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->paginated([10, 25, 50]);
    }

    /**
     * Resellers should only see their own VPN users.
     * Your table has client_id already, so use that.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('client_id', auth()->id())
            ->with('vpnServers');
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