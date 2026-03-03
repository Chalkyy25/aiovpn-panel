<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Widgets\ResellerStats;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = 1;

    public function getWidgets(): array
    {
        return [
            ResellerStats::class,
        ];
    }
}
