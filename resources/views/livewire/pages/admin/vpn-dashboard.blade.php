{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}

@push('scripts')
<script>
  window.VPN_DASHBOARD_CONFIG = {
    disconnectFallbackPattern: @json(route('admin.servers.disconnect', ['server' => '__SID__'])),
    csrf: @json(csrf_token()),
  };
</script>
@endpush

<div
  x-data="vpnDashboard(window.Livewire.find('{{ $this->getId() }}'))"
  x-init="
    init(
      @js($serverMeta),
      @js($seedUsersByServer)
    )
  "
  class="space-y-6"
>

  {{-- HEADER --}}
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">

    <div class="flex items-center gap-3 shrink-0">
      <x-button
        type="button"
        variant="secondary"
        size="sm"
        x-bind:disabled="refreshing"
        @click.prevent="refreshNow()"
      >
        <span x-show="!refreshing">Refresh</span>
        <span x-show="refreshing" x-cloak>Refreshing…</span>
      </x-button>

      <div class="text-xs text-[var(--aio-sub)] text-right">
        <span class="hidden sm:inline">Updated</span>
        <span class="font-medium text-[var(--aio-header)]" x-text="lastUpdated"></span>
      </div>
    </div>
  </div>

  {{-- STAT TILES --}}
  <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-4">
    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-user-group" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Online</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
      </div>
    </div>

    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-chart-bar" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Connections</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
      </div>
    </div>

    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-server" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Servers</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
      </div>
    </div>

    <div class="hidden lg:flex aio-card p-4 items-center gap-3">
      <x-icon name="o-clock" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Avg. Session</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]">
          @if($activeConnections->count() > 0)
            {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
          @else
            0m
          @endif
        </div>
      </div>
    </div>
  </div>

  {{-- FILTER --}}
  <div class="flex items-center justify-between gap-3">
    <x-button
      type="button"
      variant="secondary"
      size="sm"
      @click="toggleFilters()"
      x-bind:aria-expanded="showFilters"
      class="gap-2"
    >
      <x-icon name="o-filter" class="w-4 h-4" />
      Filter
    </x-button>

    <div class="text-xs text-[var(--aio-sub)]" x-show="selectedServerId !== null" x-cloak>
      Showing:
      <span class="font-medium text-[var(--aio-ink)]" x-text="serverMeta[selectedServerId]?.name ?? 'Unknown'"></span>
    </div>
  </div>

  {{-- SERVER FILTER PANEL --}}
  <div x-show="showFilters" x-transition x-cloak class="aio-card p-4 space-y-4">
    <div class="flex items-center justify-between gap-3">
      <h3 class="text-base font-semibold text-[var(--aio-ink)] flex items-center gap-2">
        <x-icon name="o-filter" class="h-4 w-4 text-[var(--aio-accent)]" />
        Filter by server
      </h3>

      <x-button type="button" variant="ghost" size="sm" @click="showFilters=false">
        Close
      </x-button>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        @click="selectServer(null)"
        class="aio-pill"
        :class="selectedServerId===null ? 'ring-2 ring-[var(--aio-accent)]' : ''"
      >
        All
        <x-badge tone="blue" size="sm">
          <span x-text="totals.active_connections"></span>
        </x-badge>
      </button>

      <template x-for="(meta, sid) in serverMeta" :key="sid">
        <button
          type="button"
          @click="selectServer(Number(sid))"
          class="aio-pill"
          :class="selectedServerId===Number(sid) ? 'ring-2 ring-[var(--aio-accent)]' : ''"
        >
          <span x-text="meta.name"></span>
          <x-badge tone="slate" size="sm">
            <span x-text="serverUsersCount(Number(sid))"></span>
          </x-badge>
        </button>
      </template>
    </div>
  </div>

  {{-- ACTIVE CONNECTIONS (Desktop table) --}}
  <x-table title="Active Connections" subtitle="Live connections across all servers">
    <thead class="hidden md:table-header-group">
      <tr>
        <th>User</th>
        <th>Server</th>
        <th>Client IP</th>
        <th>Virtual IP</th>
        <th>Protocol</th>
        <th>Connected</th>
        <th>Transfer</th>
        <th class="cell-right">Actions</th>
      </tr>
    </thead>

    <tbody class="hidden md:table-row-group">
      <template x-for="row in activeRows()" :key="row.__key">
        <tr>
          <td><span class="font-medium" x-text="row.username"></span></td>
          <td class="cell-muted" x-text="row.server_name"></td>
          <td x-text="row.client_ip || '—'"></td>
          <td x-text="row.virtual_ip || '—'"></td>

          <td>
            <x-badge tone="slate" size="sm">
              <span x-text="(row.protocol || 'UNKNOWN').toUpperCase()"></span>
            </x-badge>
          </td>

          <td x-text="row.connected_human ?? '—'"></td>
          <td x-text="row.formatted_bytes ?? '—'"></td>

          <td class="cell-right">
            <x-button type="button" variant="danger" size="sm" @click.prevent="disconnect(row)">
              Disconnect
            </x-button>
          </td>
        </tr>
      </template>

      <tr x-show="activeRows().length===0" x-cloak>
        <td colspan="8" class="text-center text-[var(--aio-sub)] py-6">
          No active connections
        </td>
      </tr>
    </tbody>
  </x-table>

  {{-- Mobile cards --}}
  <div class="md:hidden aio-card overflow-hidden">
    <div class="px-4 py-3 border-b border-[var(--aio-border)] flex items-center justify-between">
      <div class="text-sm font-semibold text-[var(--aio-ink)]">Active Connections</div>
      <div class="text-xs text-[var(--aio-sub)]">
        <span x-text="activeRows().length"></span> rows
      </div>
    </div>

    <div class="divide-y" style="border-color: var(--aio-border)">
      <template x-for="row in activeRows()" :key="row.__key">
        <div class="p-4 space-y-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="font-medium text-[var(--aio-ink)] truncate" x-text="row.username"></div>
              <div class="text-xs text-[var(--aio-sub)] truncate" x-text="row.server_name"></div>

              <div class="mt-2">
                <x-badge tone="slate" size="sm">
                  <span x-text="(row.protocol || 'UNKNOWN').toUpperCase()"></span>
                </x-badge>
              </div>
            </div>

            <x-button type="button" variant="danger" size="sm" @click.prevent="disconnect(row)">
              Disconnect
            </x-button>
          </div>

          <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Client IP</div>
              <div x-text="row.client_ip || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Virtual IP</div>
              <div x-text="row.virtual_ip || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Connected</div>
              <div x-text="row.connected_human || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Transfer</div>
              <div x-text="row.formatted_bytes || '—'"></div>
              <div class="text-[10px] text-[var(--aio-sub)]">
                ↓<span x-text="row.down_mb || '0.00'"></span>MB
                ↑<span x-text="row.up_mb || '0.00'"></span>MB
              </div>
            </div>
          </div>
        </div>
      </template>

      <div x-show="activeRows().length===0" x-cloak class="p-6 text-center text-[var(--aio-sub)]">
        No active connections
      </div>
    </div>
  </div>

</div>
