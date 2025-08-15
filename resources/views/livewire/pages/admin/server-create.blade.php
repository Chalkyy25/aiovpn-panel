<x-slot name="header">
  <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-5xl mx-auto space-y-6">
  <form wire:submit.prevent="create" class="space-y-6">

    {{-- SERVER DETAILS --}}
    <section class="aio-card p-5 md:p-6">
      <div class="flex items-center gap-3 mb-4">
        <span class="w-1.5 h-6 rounded {{-- accent bar --}} accent-cya"></span>
        <div>
          <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Server Details</h3>
          <p class="text-sm text-[var(--aio-sub)]">Core identity & SSH access.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="col-span-1 md:col-span-2">
          <label class="aio-label">Server Name</label>
          <input type="text" wire:model.live="name" class="aio-input" placeholder="Spain-01">
          @error('name')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div class="col-span-1 md:col-span-2">
          <label class="aio-label">IP Address</label>
          <input type="text" wire:model.live="ip" class="aio-input" placeholder="203.0.113.10">
          @error('ip')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label">Protocol</label>
          <select wire:model.live="protocol" class="aio-select">
            <option>OpenVPN</option>
            <option>WireGuard</option>
          </select>
          @error('protocol')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label">SSH Port</label>
          <input type="number" wire:model.live="sshPort" class="aio-input" placeholder="22" min="1" max="65535">
          @error('sshPort')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label">SSH Username</label>
          <input type="text" wire:model.live="sshUsername" class="aio-input" placeholder="root">
          @error('sshUsername')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label">SSH Login Type</label>
          <select wire:model.live="sshType" class="aio-select">
            <option value="key">SSH Key</option>
            <option value="password">Password</option>
          </select>
          @error('sshType')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        @if($sshType === 'password')
          <div class="md:col-span-2">
            <label class="aio-label">SSH Password</label>
            <input type="password" wire:model.live="sshPassword" class="aio-input" placeholder="••••••••">
            @error('sshPassword')<p class="aio-error">{{ $message }}</p>@enderror
          </div>
        @endif
      </div>
    </section>

    {{-- ADVANCED SETTINGS --}}
    <section class="aio-card p-5 md:p-6">
      <div class="flex items-center gap-3 mb-4">
        <span class="w-1.5 h-6 rounded accent-pup"></span>
        <div>
          <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Advanced Settings</h3>
          <p class="text-sm text-[var(--aio-sub)]">OpenVPN / networking options.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="aio-label">OpenVPN Port</label>
          <input type="number" wire:model.live="port" class="aio-input" placeholder="1194">
          @error('port')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label">Transport Protocol</label>
          <select wire:model.live="transport" class="aio-select">
            <option value="udp">UDP</option>
            <option value="tcp">TCP</option>
          </select>
          @error('transport')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div class="md:col-span-2">
          <label class="aio-label">DNS Resolver</label>
          <input type="text" wire:model.live="dns" class="aio-input" placeholder="1.1.1.1">
          @error('dns')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <label class="aio-check">
          <input type="checkbox" wire:model.live="enableIPv6" class="aio-checkbox">
          <span>Enable IPv6</span>
        </label>

        <label class="aio-check">
          <input type="checkbox" wire:model.live="enableLogging" class="aio-checkbox">
          <span>Enable Logging</span>
        </label>

        <label class="aio-check">
          <input type="checkbox" wire:model.live="enableProxy" class="aio-checkbox">
          <span>Enable Proxy</span>
        </label>

        <label class="aio-check">
          <input type="checkbox" wire:model.live="header1" class="aio-checkbox">
          <span>Custom Header 1</span>
        </label>

        <label class="aio-check">
          <input type="checkbox" wire:model.live="header2" class="aio-checkbox">
          <span>Custom Header 2</span>
        </label>
      </div>
    </section>

    {{-- ACTIONS --}}
    <div class="flex items-center justify-end gap-3">
      <a href="{{ route('admin.servers.index') }}" class="aio-pill bg-white/10 hover:bg-white/15">Cancel</a>
      <button type="submit" class="aio-pill pill-neon hover:shadow-glow inline-flex items-center gap-2">
        🚀 Deploy Server
      </button>
    </div>
  </form>
</div>