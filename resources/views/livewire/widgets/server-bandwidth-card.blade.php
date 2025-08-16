<div wire:poll.10s="sample" class="rounded-lg border border-white/10 bg-white/5 p-4">
  <div class="flex items-center justify-between">
    <h3 class="text-sm font-semibold text-[var(--aio-ink)]">
      {{ $server->name }} • Bandwidth
    </h3>
    <span class="text-xs text-[var(--aio-sub)]">Active: {{ $active_clients }}</span>
  </div>

  @if($error)
    <div class="mt-2 text-xs text-red-400">Error: {{ $error }}</div>
  @endif

  <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
    <div>
      <div class="text-xs muted">Up (Mbps)</div>
      <div class="text-2xl font-bold text-emerald-400">{{ number_format($mbps_up, 2) }}</div>
    </div>
    <div>
      <div class="text-xs muted">Down (Mbps)</div>
      <div class="text-2xl font-bold">{{ number_format($mbps_down, 2) }}</div>
    </div>
    <div>
      <div class="text-xs muted">GB / hour (Up)</div>
      <div class="text-2xl font-bold">{{ number_format($gb_per_hour_up, 2) }}</div>
    </div>
    <div>
      @php
        $limitTb = $server->bandwidth_limit_tb ?? 4;
        $pct = $limitTb > 0 ? ($projected_tb_month / $limitTb) : 0;
        $cls = $pct >= 0.9 ? 'text-rose-400' : ($pct >= 0.7 ? 'text-amber-400' : 'text-emerald-400');
      @endphp
      <div class="text-xs muted">Projected / month</div>
      <div class="text-2xl font-bold {{ $cls }}">{{ number_format($projected_tb_month, 2) }} TB</div>
      <div class="mt-1 text-[11px] text-[var(--aio-sub)]">Limit: {{ $limitTb }} TB • {{ (int)round($pct*100) }}%</div>
    </div>
  </div>

  <div class="mt-2 text-[11px] text-[var(--aio-sub)]">
    * Projection uses last ~10s rate × {{ $hours_per_day }} h/day × 30 days.
  </div>
</div>