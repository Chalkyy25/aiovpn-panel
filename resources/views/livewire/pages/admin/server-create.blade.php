<x-slot name="header">
  <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-5xl mx-auto">
  <form wire:submit.prevent="create" class="space-y-6">

    {{-- SERVER DETAILS --}}
    <section class="aio-card p-5 md:p-6">
      <div class="flex items-center gap-3 mb-5">
        <span class="w-1.5 h-6 rounded accent-cya"></span>
        <div>
          <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Server Details</h3>
          <p class="text-sm text-[var(--aio-sub)]">Core identity & SSH access.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="aio-label" for="srv_name">Server Name</label>
          <input id="srv_name" type="text" wire:model.live="name" class="aio-input" placeholder="Spain‑01">
          @error('name')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div class="md:col-span-2">
          <label class="aio-label" for="srv_ip">IP Address</label>
          <input id="srv_ip" type="text" wire:model.live="ip" class="aio-input" placeholder="203.0.113.10">
          @error('ip')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label" for="proto">Protocol</label>
          <select id="proto" wire:model.live="protocol" class="aio-select">
            <option>OpenVPN</option>
            <option>WireGuard</option>
          </select>
          @error('protocol')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label" for="ssh_port">SSH Port</label>
          <input id="ssh_port" type="number" min="1" max="65535" wire:model.live="sshPort" class="aio-input" placeholder="22">
          @error('sshPort')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label" for="ssh_user">SSH Username</label>
          <input id="ssh_user" type="text" wire:model.live="sshUsername" class="aio-input" placeholder="root">
          @error('sshUsername')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label" for="ssh_type">SSH Login Type</label>
          <select id="ssh_type" wire:model.live="sshType" class="aio-select">
            <option value="key">SSH Key</option>
            <option value="password">Password</option>
          </select>
          @error('sshType')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        @if($sshType === 'password')
          <div class="md:col-span-2">
            <label class="aio-label" for="ssh_pwd">SSH Password</label>
            <input id="ssh_pwd" type="password" wire:model.live="sshPassword" class="aio-input" placeholder="••••••••">
            @error('sshPassword')<p class="aio-error">{{ $message }}</p>@enderror
          </div>
        @endif
      </div>
    </section>

    {{-- ADVANCED SETTINGS --}}
    <section class="aio-card p-5 md:p-6">
      <div class="flex items-center gap-3 mb-5">
        <span class="w-1.5 h-6 rounded accent-pup"></span>
        <div>
          <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Advanced Settings</h3>
          <p class="text-sm text-[var(--aio-sub)]">OpenVPN / networking options.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="aio-label" for="ovpn_port">OpenVPN Port</label>
          <input id="ovpn_port" type="number" wire:model.live="port" class="aio-input" placeholder="1194">
          @error('port')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div>
          <label class="aio-label" for="transport">Transport Protocol</label>
          <select id="transport" wire:model.live="transport" class="aio-select">
            <option value="udp">UDP</option>
            <option value="tcp">TCP</option>
          </select>
          @error('transport')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        <div class="md:col-span-2">
          <label class="aio-label" for="dns">DNS Resolver</label>
          <input id="dns" type="text" wire:model.live="dns" class="aio-input" placeholder="1.1.1.1">
          @error('dns')<p class="aio-error">{{ $message }}</p>@enderror
        </div>

        {{-- Toggles --}}
        <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-2">
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
      </div>
    </section>

    {{-- ACTIONS --}}
    <div class="flex items-center justify-end gap-3">
      <a href="{{ route('admin.servers.index') }}" class="aio-pill hover:shadow-glow">Cancel</a>
      <button type="submit" class="aio-pill pill-neon hover:shadow-glow inline-flex items-center gap-2">
        🚀 Deploy Server
      </button>
    </div>
  </form>
</div>