<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\VpnConnectionResource;
use App\Filament\Resources\VpnServerResource;
use App\Filament\Resources\VpnUserResource;
use Filament\Widgets\Widget;

class DashboardLinks extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 1,
    ];

    protected static string $view = 'filament.widgets.dashboard-links';

    protected function getViewData(): array
    {
        return [
            'links' => [
                ['label' => 'VPN Users', 'url' => VpnUserResource::getUrl('index'), 'icon' => 'heroicon-m-users'],
                ['label' => 'Servers', 'url' => VpnServerResource::getUrl('index'), 'icon' => 'heroicon-m-server'],
                ['label' => 'Connections', 'url' => VpnConnectionResource::getUrl('index'), 'icon' => 'heroicon-m-signal'],
            ],
        ];
    }
}
