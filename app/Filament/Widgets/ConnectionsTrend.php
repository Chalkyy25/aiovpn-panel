<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use Filament\Widgets\ChartWidget;

class ConnectionsTrend extends ChartWidget
{
    protected static ?string $heading = 'Connections (Last 24 Hours)';
    protected static ?string $pollingInterval = '60s';
    protected static ?int $sort = 4;
    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg'      => 3, // full-width row on desktop
    ];

    protected function getData(): array
    {
        $now = now();
        $hours = collect(range(23, 0))->map(fn ($i) => $now->copy()->subHours($i));

        $labels = [];
        $data = [];

        foreach ($hours as $hour) {
            $start = $hour->copy()->startOfHour();
            $end   = $hour->copy()->endOfHour();

            // Number of sessions that started in this hour (source of truth: vpn_connections)
            $count = VpnConnection::query()
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('connected_at', [$start, $end])
                        ->orWhere(function ($q) use ($start, $end) {
                            $q->whereNull('connected_at')
                                ->whereBetween('created_at', [$start, $end]);
                        });
                })
                ->count();

            $labels[] = $start->format('H:00');
            $data[]   = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Connections',
                    'data' => $data,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
