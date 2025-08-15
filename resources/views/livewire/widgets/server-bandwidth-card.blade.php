<div wire:poll.10s="sample" class="rounded-lg border border-gray-700 bg-gray-900 p-4">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-300">
            {{ $server->name }} • Bandwidth
        </h3>
        <span class="text-xs text-gray-400">Active: {{ $active_clients }}</span>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <div class="text-xs text-gray-400">Up (Mbps)</div>
            <div class="text-2xl font-bold {{ $mbps_up > 0 ? 'text-emerald-400' : 'text-gray-300' }}">
                {{ number_format($mbps_up, 2) }}
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-400">Down (Mbps)</div>
            <div class="text-2xl font-bold text-gray-300">
                {{ number_format($mbps_down, 2) }}
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-400">GB / hour (Up)</div>
            <div class="text-2xl font-bold text-gray-300">
                {{ number_format($gb_per_hour_up, 2) }}
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-400">Projected / month</div>
            @php
                // Show red/yellow/green against a 4 TB plan by default
                $limitTb = $server->bandwidth_limit_tb ?? 4;
                $pct = $limitTb > 0 ? ($projected_tb_month / $limitTb) : 0;
                $cls = $pct >= 0.9 ? 'text-rose-400' : ($pct >= 0.7 ? 'text-amber-400' : 'text-emerald-400');
            @endphp
            <div class="text-2xl font-bold {{ $cls }}">
                {{ number_format($projected_tb_month, 2) }} TB
            </div>
            <div class="mt-1 text-[11px] text-gray-400">
                Limit: {{ $limitTb }} TB • {{ (int)round($pct*100) }}%
            </div>
        </div>
    </div>

    <div class="mt-3 text-[11px] text-gray-400">
        * Projection uses last ~10s rate × {{ $hours_per_day }} h/day × 30 days.
    </div>
</div>