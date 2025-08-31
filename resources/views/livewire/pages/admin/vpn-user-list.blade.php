<div wire:poll.10s class="max-w-7xl mx-auto p-4 space-y-4">

    {{-- Header + Add button --}}
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold">VPN Users</h2>

        <x-button :href="route('admin.vpn-users.create')" variant="light" size="sm" class="gap-2">
            <span class="text-base leading-none">＋</span>
            <span>Add User</span>
        </x-button>
    </div>

    @if (session()->has('message'))
        <div class="aio-pill pill-neon inline-block">
            {{ session('message') }}
        </div>
    @endif

    {{-- Search --}}
    <div>
        <input wire:model.debounce.300ms="search"
               type="text"
               placeholder="Search users..."
               class="w-full md:w-1/3 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-[var(--aio-ink)] placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
    </div>

    {{-- Responsive list: cards on mobile, table on md+ --}}
    <div class="aio-card overflow-x-auto">

      {{-- md+: classic table --}}
      <table class="min-w-full text-sm hidden md:table">
        <thead class="bg-white/5">
          <tr class="text-[var(--aio-sub)] uppercase text-xs">
            <th class="px-6 py-3 text-left">Username</th>
            <th class="px-6 py-3 text-left">Password</th>
            <th class="px-6 py-3 text-left">Servers</th>
            <th class="px-6 py-3 text-left">Expires</th>
            <th class="px-6 py-3 text-left">Status</th>
            <th class="px-6 py-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/10">
          @forelse ($users as $user)
            <tr>
              {{-- Username --}}
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-3">
                  <span class="h-2.5 w-2.5 rounded-full {{ $user->is_online ? 'bg-[var(--aio-neon)]' : 'bg-gray-500' }}"></span>
                  <div>
                    <div x-data="{ copied:false }" class="flex items-center gap-2">
                      <span class="font-medium text-[var(--aio-ink)]">{{ $user->username }}</span>

                      <button type="button" class="p-1 rounded hover:bg-white/10 focus:outline-none"
                        @click="navigator.clipboard.writeText('{{ $user->username }}');copied=true;setTimeout(()=>copied=false,1500)"
                        :aria-label="copied ? 'Copied' : 'Copy username'">
                        <template x-if="!copied">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[var(--aio-cya)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M8 16h8a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-5l-3 3v6a2 2 0 0 0 2 2z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M15 20H7a2 2 0 0 1-2-2V9" />
                          </svg>
                        </template>
                        <template x-if="copied">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z" clip-rule="evenodd"/>
                          </svg>
                        </template>
                      </button>
                    </div>

                    @if($user->is_online && $user->online_since)
                      <div class="text-xs text-[var(--aio-neon)]">Online – {{ $user->online_since->diffForHumans() }}</div>
                    @elseif($user->last_disconnected_at)
                      <div class="text-xs text-gray-400">Offline – {{ $user->last_disconnected_at->diffForHumans() }}</div>
                    @else
                      <div class="text-xs text-gray-500">Never connected</div>
                    @endif
                  </div>
                </div>
              </td>

              {{-- Password --}}
              <td class="px-6 py-4 whitespace-nowrap">
                @if($user->plain_password)
                  <div x-data="{ copied:false }" class="flex items-center gap-2">
                    <span class="font-mono aio-pill pill-cya text-xs">{{ $user->plain_password }}</span>

                    <button type="button" class="p-1 rounded hover:bg-white/10 focus:outline-none"
                      @click="navigator.clipboard.writeText('{{ $user->plain_password }}');copied=true;setTimeout(()=>copied=false,1500)"
                      :aria-label="copied ? 'Copied' : 'Copy password'">
                      <template x-if="!copied">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[var(--aio-cya)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M8 16h8a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-5l-3 3v6a2 2 0 0 0 2 2z" />
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M15 20H7a2 2 0 0 1-2-2V9" />
                        </svg>
                      </template>
                      <template x-if="copied">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                      </template>
                    </button>
                  </div>
                @else
                  <span class="text-gray-500 text-xs">Encrypted</span>
                @endif
              </td>

              {{-- Servers --}}
              <td class="px-6 py-4">
                @if($user->vpnServers->count())
                  <div class="flex flex-wrap gap-1">
                    @foreach($user->vpnServers as $server)
                      <a href="{{ route('admin.servers.show', $server->id) }}" class="aio-pill pill-mag text-xs hover:shadow-glow">
                        {{ $server->name }}
                      </a>
                    @endforeach
                  </div>
                @else
                  <span class="text-gray-500">No servers</span>
                @endif
              </td>

              {{-- Expiry --}}
              <td class="px-6 py-4 whitespace-nowrap">
                {{ $user->expires_at ? \Carbon\Carbon::parse($user->expires_at)->format('d M Y') : 'Never' }}
              </td>

              {{-- Status --}}
              <td class="px-6 py-4 whitespace-nowrap space-y-1">
                <span class="aio-pill {{ $user->is_active ? 'pill-neon' : 'bg-red-500/20 text-red-400' }}">
                  {{ $user->is_active ? 'Active' : 'Inactive' }}
                </span>
                @if($user->is_online)
                  <span class="aio-pill pill-cya">{{ $user->activeConnections->count() }} conn</span>
                @endif
              </td>

              {{-- Actions --}}
              <td class="px-6 py-4 whitespace-nowrap text-xs space-x-2">
                <a href="{{ route('admin.vpn-users.edit', $user->id) }}" class="text-[var(--aio-neon)] hover:underline">Edit</a>
                <button wire:click="generateOvpn({{ $user->id }})" class="text-[var(--aio-cya)] hover:underline">OpenVPN</button>
                <button wire:click="generateWireGuard({{ $user->id }})" class="text-[var(--aio-mag)] hover:underline">WireGuard</button>
                <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
                  @csrf
                  <button type="submit" class="text-[var(--aio-pup)] hover:underline" title="Login as this client">Login</button>
                </form>
                <button wire:click="toggleActive({{ $user->id }})" class="text-yellow-400 hover:underline">
                  {{ $user->is_active ? 'Disable' : 'Enable' }}
                </button>
                <button wire:click="deleteUser({{ $user->id }})" onclick="return confirm('Delete this user?')" class="text-red-400 hover:underline">Delete</button>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No VPN users found</td></tr>
          @endforelse
        </tbody>
      </table>

      {{-- Mobile: stacked cards --}}
      <div class="md:hidden divide-y divide-white/10">
        @forelse ($users as $user)
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 mt-1 rounded-full {{ $user->is_online ? 'bg-[var(--aio-neon)]' : 'bg-gray-500' }}"></span>
                <div>
                  <div x-data="{ copied:false }" class="flex items-center gap-2">
                    <div class="font-semibold text-[var(--aio-ink)]">{{ $user->username }}</div>
                    <button type="button" class="p-1 rounded hover:bg-white/10 focus:outline-none"
                      @click="navigator.clipboard.writeText('{{ $user->username }}');copied=true;setTimeout(()=>copied=false,1500)"
                      :aria-label="copied ? 'Copied' : 'Copy username'">
                      <template x-if="!copied">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[var(--aio-cya)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M8 16h8a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-5l-3 3v6a2 2 0 0 0 2 2z" />
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M15 20H7a2 2 0 0 1-2-2V9" />
                        </svg>
                      </template>
                      <template x-if="copied">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                      </template>
                    </button>
                  </div>

                  @if($user->is_online && $user->online_since)
                    <div class="text-[10px] text-[var(--aio-neon)]">Online – {{ $user->online_since->diffForHumans() }}</div>
                  @elseif($user->last_disconnected_at)
                    <div class="text-[10px] text-gray-400">Offline – {{ $user->last_disconnected_at->diffForHumans() }}</div>
                  @else
                    <div class="text-[10px] text-gray-500">Never connected</div>
                  @endif
                </div>
              </div>
              <span class="aio-pill {{ $user->is_active ? 'pill-neon' : 'bg-red-500/20 text-red-400' }}">
                {{ $user->is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>

            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
              <div>
                <dt class="text-[var(--aio-sub)]">Password</dt>
                <dd class="mt-0.5">
                  @if($user->plain_password)
                    <div x-data="{ copied:false }" class="flex items-center gap-2">
                      <span class="font-mono bg-white/5 rounded px-1.5 py-0.5">{{ $user->plain_password }}</span>
                      <button type="button" class="p-1 rounded hover:bg-white/10 focus:outline-none"
                        @click="navigator.clipboard.writeText('{{ $user->plain_password }}');copied=true;setTimeout(()=>copied=false,1500)"
                        :aria-label="copied ? 'Copied' : 'Copy password'">
                        <template x-if="!copied">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-[var(--aio-cya)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M8 16h8a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-5l-3 3v6a2 2 0 0 0 2 2z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M15 20H7a2 2 0 0 1-2-2V9" />
                          </svg>
                        </template>
                        <template x-if="copied">
                          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z" clip-rule="evenodd"/>
                          </svg>
                        </template>
                      </button>
                    </div>
                  @else
                    <span class="text-gray-500">Encrypted</span>
                  @endif
                </dd>
              </div>
              <div>
                <dt class="text-[var(--aio-sub)]">Expires</dt>
                <dd class="mt-0.5">
                  {{ $user->expires_at ? \Carbon\Carbon::parse($user->expires_at)->isoFormat('D MMM YYYY') : 'Never' }}
                </dd>
              </div>
              <div class="col-span-2">
                <dt class="text-[var(--aio-sub)]">Servers</dt>
                <dd class="mt-0.5 flex flex-wrap gap-1">
                  @if($user->vpnServers->count())
                    @foreach($user->vpnServers as $server)
                      <a href="{{ route('admin.servers.show', $server->id) }}" class="aio-pill pill-mag">{{ $server->name }}</a>
                    @endforeach
                  @else
                    <span class="text-gray-500">No servers</span>
                  @endif
                </dd>
              </div>
              @if($user->is_online)
                <div>
                  <dt class="text-[var(--aio-sub)]">Connections</dt>
                  <dd class="mt-0.5 aio-pill pill-cya inline-block">{{ $user->activeConnections->count() }} conn</dd>
                </div>
              @endif
            </dl>

            <div class="mt-3 flex flex-wrap gap-3 text-xs">
              <a href="{{ route('admin.vpn-users.edit', $user->id) }}" class="text-[var(--aio-neon)] underline">Edit</a>
              <button wire:click="generateOvpn({{ $user->id }})" class="text-[var(--aio-cya)] underline">OpenVPN</button>
              <button wire:click="generateWireGuard({{ $user->id }})" class="text-[var(--aio-mag)] underline">WireGuard</button>
              <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
                @csrf
                <button type="submit" class="text-[var(--aio-pup)] underline" title="Login as this client">Login</button>
              </form>
              <button wire:click="toggleActive({{ $user->id }})" class="text-yellow-400 underline">
                {{ $user->is_active ? 'Disable' : 'Enable' }}
              </button>
              <button wire:click="deleteUser({{ $user->id }})" onclick="return confirm('Delete this user?')" class="text-red-400 underline">
                Delete
              </button>
            </div>
          </div>
        @empty
          <div class="p-6 text-center text-gray-500">No VPN users found</div>
        @endforelse
      </div>
    </div>

    <div>
        {{ $users->links() }}
    </div>
</div>