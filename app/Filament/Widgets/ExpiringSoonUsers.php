<?php

namespace App\Filament\Widgets;

use App\Models\VpnUser;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringSoonUsers extends BaseWidget
{
    protected static ?string $heading = 'Expiring in 7 Days';
    protected static ?string $pollingInterval = '60s';
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 2,
    ];

    public function table(Table $table): Table
    {
        $now = now();
        $end = $now->copy()->addDays(7);

        return $table
            ->query(
                VpnUser::query()
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [$now, $end])
                    ->orderBy('expires_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_in')
                    ->label('Time left')
                    ->state(fn (VpnUser $u): string => $u->expires_at ? $u->expires_at->diffForHumans(now(), [
                        'parts' => 2,
                        'short' => true,
                        'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                    ]) : '—')
                    ->toggleable(),
            ])
            ->defaultPaginationPageOption(10);
    }
}
