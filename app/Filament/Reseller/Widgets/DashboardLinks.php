<?php

namespace App\Filament\Reseller\Widgets;

use Filament\Widgets\Widget;

class DashboardLinks extends Widget
{
    protected static ?int $sort = 30;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 1,
    ];

    protected static string $view = 'filament.reseller.widgets.dashboard-links';
}
