<x-section-card :title="$server->name . ' • Bandwidth'" wire:poll.10s="sample">

  @if($error)
    <div class="mb-3 text-xs text-red-400">Error: {{ $error }}</div>
  @endif

  @php
    $limitTb = $server->bandwidth_limit_tb ?? 4;
    $pct     = $limitTb > 0 ? ($projected_tb_month / $limitTb) : 0;

    // choose a variant for the Projected card by threshold
    $projectedVariant = $pct >= 0.9 ? 'mag' : ($pct >= 0.7 ? 'cya' : 'neon'); // red-ish / warning / good
    $pctLabel = (int) round($pct * 100);
  @endphp

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <x-stat-card
      title="Up (Mbps)"
      :value="number_format($mbps_up, 2)"
      icon="o-arrow-up-tray"
      variant="neon"
      hint="10s avg"
    />

    <x-stat-card
      title="Down (Mbps)"
      :value="number_format($mbps_down, 2)"
      icon="o-arrow-down-tray"
      variant="cya"
      hint="10s avg"
    />

    <x-stat-card
      title="GB / hour (Up)"
      :value="number_format($gb_per_hour_up, 2)"
      icon="o-chart-bar"
      variant="pup"
    />

    <x-stat-card
      :title="'Projected / month • Limit: ' . $limitTb . ' TB • ' . $pctLabel . '%'"
      :value="number_format($projected_tb_month, 2) . ' TB'"
      icon="o-bolt"
      :variant="$projectedVariant"
    />
  </div>

  <div class="mt-2 text-[11px] text-[var(--aio-sub)]">
    * Projection = last ~10s rate × {{ $hours_per_day }} h/day × 30 days.
  </div>
</x-section-card>