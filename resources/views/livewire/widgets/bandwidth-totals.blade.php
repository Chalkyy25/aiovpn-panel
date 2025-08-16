<div wire:poll.10s class="rounded-lg border border-white/10 bg-white/5 p-4">
  <div class="text-sm text-[var(--aio-sub)]">Fleet Total</div>
  <div class="mt-1 grid grid-cols-2 gap-4">
    <div>
      <div class="text-xs muted">Total Up (Mbps)</div>
      <div class="text-2xl font-bold text-emerald-400">{{ number_format($mbps_up, 2) }}</div>
    </div>
    <div>
      <div class="text-xs muted">Projected / month</div>
      <div class="text-2xl font-bold text-[var(--aio-ink)]">{{ number_format($projected_tb_month, 2) }} TB</div>
    </div>
  </div>
</div>