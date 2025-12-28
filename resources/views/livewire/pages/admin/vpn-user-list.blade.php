<div wire:poll.10s class="max-w-7xl mx-auto p-4 space-y-4">

  {{-- Config generation progress --}}
  <div wire:poll.1s="pollConfigProgress">
    @if($configUserId && $configProgress > 0 && $configProgress < 100)
      <x-section-card title="Generating Config Pack" subtitle="{{ $configMessage ?: 'Please wait…' }}" class="p-0" flush>
        <div class="p-4">
          <div class="w-full rounded-full h-2 overflow-hidden bg-[var(--aio-soft)] border border-[var(--aio-border)]">
            <div class="h-2 bg-[var(--aio-accent)] transition-all"
                 style="width: {{ $configProgress }}%"></div>
          </div>
          <div class="mt-2 text-xs text-[var(--aio-sub)]">{{ $configProgress }}%</div>
        </div>
      </x-section-card>
    @elseif($configUserId && $configProgress === 100)
      <div class="aio-pill pill-success inline-block text-xs">
        {{ $configMessage ?: 'Config pack complete.' }}
      </div>
    @endif
  </div>

  {{-- Header --}}
  <div class="flex items-center justify-between gap-3">

    <x-button :href="route('admin.vpn-users.create')" variant="primary" size="sm" class="gap-2">
      <span class="text-base leading-none">＋</span>
      <span>Add User</span>
    </x-button>
  </div>

  @if (session()->has('message'))
    <div class="aio-pill pill-success inline-block">
      {{ session('message') }}
    </div>
  @endif

  {{-- Search --}}
  <x-section-card title="Search" flush class="p-0">
    <div class="p-4">
      <input wire:model.debounce.300ms="search"
             type="text"
             placeholder="Search users..."
             class="form-input w-full md:w-1/3">
    </div>
  </x-section-card>

  {{-- Table / list --}}
  <x-section-card
    title="Users"
    subtitle="Showing {{ $users->count() }} results"
    flush
  >
    <x-slot:actions>
      <span class="aio-pill text-xs">Auto refresh: 10s</span>
    </x-slot:actions>

    {{-- md+: table --}}
    <div class="hidden md:block overflow-x-auto">
      <table class="aio-table">
        <thead>
          <tr>
            <th>Credentials</th>
            <th>Servers</th>
            <th>Expires</th>
            <th>Status</th>
            <th class="cell-right">Actions</th>
          </tr>
        </thead>

        <tbody>
          @forelse ($users as $user)
            <tr>

              {{-- Credentials --}}
              <td class="cell-nowrap">
                <div x-data="{ copied:false }" class="flex items-start gap-3">
                  <div class="font-mono text-sm leading-tight">
                    <div><span class="cell-muted">Username:</span> <span class="font-semibold">{{ $user->username }}</span></div>
                    <div><span class="cell-muted">Password:</span> {{ $user->plain_password ?? '******' }}</div>
                  </div>

                  @if($user->plain_password)
                    <button type="button"
                            class="p-2 rounded-md border border-transparent hover:border-[var(--aio-border)] hover:bg-[var(--aio-hover)]"
                            @click="navigator.clipboard.writeText('Username: {{ $user->username }}\nPassword: {{ $user->plain_password }}');copied=true;setTimeout(()=>copied=false,1500)"
                            :aria-label="copied ? 'Copied' : 'Copy credentials'">
                      <span x-show="!copied" class="text-[var(--aio-sub)]">Copy</span>
                      <span x-show="copied" class="text-[var(--aio-success)]">✓</span>
                    </button>
                  @endif
                </div>
              </td>

              {{-- Servers --}}
              <td>
                @if($user->vpnServers->count())
                  <div class="flex flex-wrap gap-1">
                    @foreach($user->vpnServers as $server)
                      <a href="{{ route('admin.servers.show', $server->id) }}"
                         class="aio-pill text-xs hover:bg-[var(--aio-hover)]">
                        {{ $server->name }}
                      </a>
                    @endforeach
                  </div>
                @else
                  <span class="cell-muted">No servers</span>
                @endif
              </td>

              {{-- Expiry --}}
              <td class="cell-nowrap">
                {{ $user->expires_at ? \Carbon\Carbon::parse($user->expires_at)->format('d M Y') : 'Never' }}
              </td>

              {{-- Status --}}
              <td class="cell-nowrap">
                <div class="flex flex-wrap items-center gap-2">
                  @if($user->is_active)
                    <span class="aio-pill pill-success text-xs">Active</span>
                  @else
                    <span class="aio-pill pill-danger text-xs">Inactive</span>
                  @endif

                  @if($user->is_online)
                    <span class="aio-pill text-xs">
                      {{ $user->activeConnections->count() }} conn
                    </span>
                  @endif
                </div>
              </td>

              {{-- Actions --}}
              <td class="cell-right cell-nowrap">
                <div class="flex items-center justify-end gap-2">

                  <x-button :href="route('admin.vpn-users.edit', $user->id)" variant="secondary" size="sm">
                    Edit
                  </x-button>

                  <x-button type="button" wire:click="generateOvpn({{ $user->id }})" variant="secondary" size="sm">
                    Configs
                  </x-button>

                  <x-button type="button" wire:click="generateWireGuard({{ $user->id }})" variant="secondary" size="sm">
                    Ensure WG
                  </x-button>

                  <x-button type="button" wire:click="forceRemoveWireGuardPeer({{ $user->id }})" variant="secondary" size="sm">
                    Revoke WG
                  </x-button>

                  @php $linked = $user->vpnServers ?? collect(); @endphp

                  @if($linked->count() === 1)
                    <x-button :href="route('admin.vpn-users.wg.download', [$user->id, $linked->first()->id])" variant="secondary" size="sm">
                      WG
                    </x-button>
                  @elseif($linked->count() > 1)
                    <details class="relative">
                      <summary class="cursor-pointer select-none aio-pill text-xs">WG ▾</summary>
                      <div class="absolute right-0 mt-2 w-44 bg-[var(--aio-card)] border border-[var(--aio-border)] rounded-md shadow-lg overflow-hidden z-50">
                        @foreach($linked as $s)
                          <a href="{{ route('admin.vpn-users.wg.download', [$user->id, $s->id]) }}"
                             class="block px-3 py-2 text-sm hover:bg-[var(--aio-hover)]">
                            {{ $s->name }}
                          </a>
                        @endforeach
                      </div>
                    </details>
                  @endif

                  <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm">
                      Login
                    </x-button>
                  </form>

                  <x-button type="button" wire:click="toggleActive({{ $user->id }})" variant="secondary" size="sm">
                    {{ $user->is_active ? 'Disable' : 'Enable' }}
                  </x-button>

                  <x-button type="button"
                            wire:click="deleteUser({{ $user->id }})"
                            onclick="return confirm('Delete this user?')"
                            variant="danger"
                            size="sm">
                    Delete
                  </x-button>
                </div>
              </td>

            </tr>
          @empty
            <tr>
              <td colspan="5" class="cell-muted py-8 text-center">
                No VPN users found
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="md:hidden divide-y border-t border-[var(--aio-border)]">
      @forelse ($users as $user)
        <div class="p-4 space-y-3">

          <div class="flex items-start justify-between gap-3">
            <div x-data="{ copied:false }" class="space-y-1">
              <div class="font-mono text-sm leading-tight">
                <div><span class="cell-muted">Username:</span> <span class="font-semibold">{{ $user->username }}</span></div>
                <div><span class="cell-muted">Password:</span> {{ $user->plain_password ?? '******' }}</div>
              </div>

              @if($user->plain_password)
                <button type="button"
                        class="aio-pill text-xs hover:bg-[var(--aio-hover)]"
                        @click="navigator.clipboard.writeText('Username: {{ $user->username }}\nPassword: {{ $user->plain_password }}');copied=true;setTimeout(()=>copied=false,1500)">
                  <span x-show="!copied">Copy</span>
                  <span x-show="copied" class="text-[var(--aio-success)]">✓</span>
                </button>
              @endif
            </div>

            @if($user->is_active)
              <span class="aio-pill pill-success text-xs">Active</span>
            @else
              <span class="aio-pill pill-danger text-xs">Inactive</span>
            @endif
          </div>

          <dl class="grid grid-cols-2 gap-3 text-xs">
            <div>
              <dt class="text-[var(--aio-sub)]">Expires</dt>
              <dd class="mt-1">{{ $user->expires_at ? \Carbon\Carbon::parse($user->expires_at)->isoFormat('D MMM YYYY') : 'Never' }}</dd>
            </div>

            @if($user->is_online)
              <div>
                <dt class="text-[var(--aio-sub)]">Connections</dt>
                <dd class="mt-1">
                  <span class="aio-pill text-xs">{{ $user->activeConnections->count() }} conn</span>
                </dd>
              </div>
            @endif

            <div class="col-span-2">
              <dt class="text-[var(--aio-sub)]">Servers</dt>
              <dd class="mt-1 flex flex-wrap gap-1">
                @if($user->vpnServers->count())
                  @foreach($user->vpnServers as $server)
                    <a href="{{ route('admin.servers.show', $server->id) }}"
                       class="aio-pill text-xs hover:bg-[var(--aio-hover)]">
                      {{ $server->name }}
                    </a>
                  @endforeach
                @else
                  <span class="cell-muted">No servers</span>
                @endif
              </dd>
            </div>
          </dl>

          <div class="flex flex-wrap gap-2">
            <x-button :href="route('admin.vpn-users.edit', $user->id)" variant="secondary" size="sm">Edit</x-button>
            <x-button type="button" wire:click="generateOvpn({{ $user->id }})" variant="secondary" size="sm">Configs</x-button>
            <x-button type="button" wire:click="generateWireGuard({{ $user->id }})" variant="secondary" size="sm">Ensure WG</x-button>
            <x-button type="button" wire:click="forceRemoveWireGuardPeer({{ $user->id }})" variant="secondary" size="sm">Revoke WG</x-button>

            <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
              @csrf
              <x-button type="submit" variant="secondary" size="sm">Login</x-button>
            </form>

            <x-button type="button" wire:click="toggleActive({{ $user->id }})" variant="secondary" size="sm">
              {{ $user->is_active ? 'Disable' : 'Enable' }}
            </x-button>

            <x-button type="button"
                      wire:click="deleteUser({{ $user->id }})"
                      onclick="return confirm('Delete this user?')"
                      variant="danger"
                      size="sm">
              Delete
            </x-button>
          </div>
        </div>
      @empty
        <div class="p-6 text-center text-[var(--aio-sub)]">No VPN users found</div>
      @endforelse
    </div>

  </x-section-card>

  <div>
    {{ $users->links() }}
  </div>

</div>
