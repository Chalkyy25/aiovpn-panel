<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnUser;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ExpiringSoonUsers extends BaseWidget
{
    protected static ?int $sort = 40;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 2,
    ];

    public function table(Table $table): Table
    {
        $resellerId = auth()->id();
        $now = now();

        return $table
            ->heading('Expiring in 7 Days')
            ->query(
                VpnUser::query()
                    ->where('client_id', $resellerId)
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [$now, $now->copy()->addDays(7)])
                    ->orderBy('expires_at')
            )
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->label('User'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Expires'),
                Tables\Columns\TextColumn::make('package.name')
                    ->toggleable()
                    ->label('Package'),
            ]);
    }
}
