<div wire:poll.10s class="aio-card p-4">
  <div class="flex items-center justify-between mb-3">
    <h3 class="text-sm font-semibold text-[var(--aio-ink)]">
      Fleet Total
    </h3>
    <span class="text-xs text-[var(--aio-sub)]">All servers combined</span>
  </div>

  <div class="grid grid-cols-2 gap-6">
    <div>
      <div class="text-xs muted">Total Up (Mbps)</div>
      <div class="text-2xl font-bold text-emerald-400">
        {{ number_format($mbps_up, 2) }}
      </div>
    </div>
    <div>
      @php
        $limitTb = $fleet_limit_tb ?? 40; // optional global fleet cap
        $pct = $limitTb > 0 ? ($projected_tb_month / $limitTb) : 0;
        $cls = $pct >= 0.9 ? 'text-rose-400' : ($pct >= 0.7 ? 'text-amber-400' : 'text-[var(--aio-ink)]');
      @endphp
      <div class="text-xs muted">Projected / month</div>
      <div class="text-2xl font-bold {{ $cls }}">
        {{ number_format($projected_tb_month, 2) }} TB
      </div>
      <div class="mt-1 text-[11px] text-[var(--aio-sub)]">
        Limit: {{ $limitTb }} TB • {{ (int)round($pct*100) }}%
      </div>
    </div>
  </div>

  <div class="mt-3 text-[11px] text-[var(--aio-sub)]">
    Projection = current fleet rate × {{ $hours_per_day }}h/day × 30 days
  </div>
</div>