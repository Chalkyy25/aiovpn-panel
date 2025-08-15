<x-slot name="header">
  <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-5xl mx-auto space-y-6">
  <form wire:submit.prevent="create" class="space-y-6">

    {{-- ========== Server Details ========== --}}
    <div class="aio-card p-6">
      <div class="mb-4">
        <h3 class="text-lg font-bold">Server Details</h3>
        <p class="text-sm text-[var(--aio-sub)]">Core identity & SSH access.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Server Name --}}
        <div>
          <label for="name" class="block text-sm mb-1 text-[var(--aio-sub)]">Server Name</label>
          <input id="name" type="text" wire:model.live="name"
                 class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]"
                 placeholder="Spain‑01" />
          @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- IP --}}
        <div>
          <label for="ip" class="block text-sm mb-1 text-[var(--aio-sub)]">IP Address</label>
          <input id="ip" type="text" wire:model.live="ip"
                 class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]"
                 placeholder="203.0.113.10" />
          @error('ip') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Protocol --}}
        <div>
          <label for="protocol" class="block text-sm mb-1 text-[var(--aio-sub)]">Protocol</label>
          <select id="protocol" wire:model.live="protocol"
                  class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
            <option value="OpenVPN">OpenVPN</option>
            <option value="WireGuard">WireGuard</option>
          </select>
          @error('protocol') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- SSH Port + Username --}}
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="sshPort" class="block text-sm mb-1 text-[var(--aio-sub)]">SSH Port</label>
            <input id="sshPort" type="number" wire:model.live="sshPort" min="1" max="65535"
                   class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]"
                   placeholder="22" />
            @error('sshPort') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
          </div>
          <div>
            <label for="sshUsername" class="block text-sm mb-1 text-[var(--aio-sub)]">SSH Username</label>
            <input id="sshUsername" type="text" wire:model.live="sshUsername"
                   class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]"
                   placeholder="root" />
          </div>
        </div>

        {{-- SSH Type --}}
        <div>
          <label for="sshType" class="block text-sm mb-1 text-[var(--aio-sub)]">SSH Login Type</label>
          <select id="sshType" wire:model.live="sshType"
                  class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
            <option value="key">SSH Key</option>
            <option value="password">Password</option>
          </select>
          @error('sshType') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
          <p class="mt-1 text-xs text-[var(--aio-sub)]" x-show="$wire.sshType === 'key'">
            Uses key at <code class="px-1 rounded bg-white/10">storage/app/ssh_keys/id_rsa</code>
          </p>
        </div>

        {{-- SSH Password (conditional) --}}
        @if($sshType === 'password')
          <div class="md:col-span-1">
            <label for="sshPassword" class="block text-sm mb-1 text-[var(--aio-sub)]">SSH Password</label>
            <input id="sshPassword" type="password" wire:model.live="sshPassword" autocomplete="new-password"
                   class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-mag)]" />
            @error('sshPassword') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
          </div>
        @endif
      </div>
    </div>

    {{-- ========== Advanced Settings ========== --}}
    <div class="aio-card p-6">
      <div class="mb-4">
        <h3 class="text-lg font-bold">Advanced Settings</h3>
        <p class="text-sm text-[var(--aio-sub)]">OpenVPN / networking options.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Port --}}
        <div>
          <label for="port" class="block text-sm mb-1 text-[var(--aio-sub)]">OpenVPN Port</label>
          <input id="port" type="number" wire:model.live="port" min="1" max="65535"
                 class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]"
                 placeholder="1194" />
          @error('port') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Transport --}}
        <div>
          <label for="transport" class="block text-sm mb-1 text-[var(--aio-sub)]">Transport Protocol</label>
          <select id="transport" wire:model.live="transport"
                  class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
            <option value="udp">UDP</option>
            <option value="tcp">TCP</option>
          </select>
          @error('transport') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- DNS --}}
        <div class="md:col-span-2">
          <label for="dns" class="block text-sm mb-1 text-[var(--aio-sub)]">DNS Resolver</label>
          <input id="dns" type="text" wire:model.live="dns"
                 class="w-full rounded-md px-3 py-2 bg-white/5 border border-white/10 text-[var(--aio-ink)] placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]"
                 placeholder="1.1.1.1, 9.9.9.9" />
          @error('dns') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>
      </div>

      {{-- Toggles --}}
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-6">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" wire:model.live="enableIPv6" class="h-4 w-4 rounded border-white/20 bg-white/5">
          <span class="text-sm text-[var(--aio-ink)]">Enable IPv6</span>
        </label>

        <label class="inline-flex items-center gap-2">
          <input type="checkbox" wire:model.live="enableLogging" class="h-4 w-4 rounded border-white/20 bg-white/5">
          <span class="text-sm text-[var(--aio-ink)]">Enable Logging</span>
        </label>

        <label class="inline-flex items-center gap-2">
          <input type="checkbox" wire:model.live="enableProxy" class="h-4 w-4 rounded border-white/20 bg-white/5">
          <span class="text-sm text-[var(--aio-ink)]">Enable Proxy</span>
        </label>

        <label class="inline-flex items-center gap-2">
          <input type="checkbox" wire:model.live="header1" class="h-4 w-4 rounded border-white/20 bg-white/5">
          <span class="text-sm text-[var(--aio-ink)]">Custom Header 1</span>
        </label>

        <label class="inline-flex items-center gap-2">
          <input type="checkbox" wire:model.live="header2" class="h-4 w-4 rounded border-white/20 bg-white/5">
          <span class="text-sm text-[var(--aio-ink)]">Custom Header 2</span>
        </label>
      </div>
    </div>

    {{-- ========== Actions ========== --}}
    <div class="flex items-center justify-between">
      <a href="{{ route('admin.servers.index') }}" class="aio-pill hover:shadow-glow">Cancel</a>

      <button type="submit"
              class="aio-pill pill-neon hover:shadow-glow font-semibold inline-flex items-center gap-2"
              wire:loading.attr="disabled">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" opacity=".25"/>
          <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="2">
            <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/>
          </path>
        </svg>
        <span wire:loading.remove>🚀 Deploy Server</span>
        <span wire:loading>Deploying…</span>
      </button>
    </div>
  </form>
</div>

@push('scripts')
<script>
  Livewire.on('redirectToInstallStatus', (id) => {
    window.location.href = `/admin/servers/${id}/install-status`;
  });
</script>
@endpush