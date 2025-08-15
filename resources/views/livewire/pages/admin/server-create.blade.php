<x-slot name="header">
  <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-5xl mx-auto space-y-6">
  <form wire:submit.prevent="create" class="space-y-6">

    <!-- SERVER DETAILS -->
    <section class="aio-section">
      <h3 class="aio-section-title">
        <span class="w-1.5 h-6 rounded accent-cya"></span>
        Server Details
      </h3>
      <p class="aio-section-sub">Core identity & SSH access.</p>

      <div class="form-grid">
        <div class="form-group md:col-span-2">
          <label class="form-label">Server Name</label>
          <input type="text" class="form-input" wire:model.live="name" placeholder="Spain-01">
          @error('name') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group md:col-span-2">
          <label class="form-label">IP Address</label>
          <input type="text" class="form-input" wire:model.live="ip" placeholder="203.0.113.10">
          @error('ip') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
          <label class="form-label">Protocol</label>
          <select class="form-select" wire:model.live="protocol">
            <option>OpenVPN</option>
            <option>WireGuard</option>
          </select>
          @error('protocol') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
          <label class="form-label">SSH Port</label>
          <input type="number" class="form-input" wire:model.live="sshPort" placeholder="22" min="1" max="65535">
          @error('sshPort') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
          <label class="form-label">SSH Username</label>
          <input type="text" class="form-input" wire:model.live="sshUsername" placeholder="root">
          @error('sshUsername') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
          <label class="form-label">SSH Login Type</label>
          <select class="form-select" wire:model.live="sshType">
            <option value="key">SSH Key</option>
            <option value="password">Password</option>
          </select>
          @error('sshType') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        @if ($sshType === 'password')
          <div class="form-group md:col-span-2">
            <label class="form-label">SSH Password</label>
            <input type="password" class="form-input" wire:model.live="sshPassword" placeholder="••••••••">
            @error('sshPassword') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
          </div>
        @endif
      </div>
    </section>

    <!-- ADVANCED SETTINGS -->
    <section class="aio-section">
      <h3 class="aio-section-title">
        <span class="w-1.5 h-6 rounded accent-pup"></span>
        Advanced Settings
      </h3>
      <p class="aio-section-sub">OpenVPN / networking options.</p>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">OpenVPN Port</label>
          <input type="number" class="form-input" wire:model.live="port" placeholder="1194">
          @error('port') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
          <label class="form-label">Transport Protocol</label>
          <select class="form-select" wire:model.live="transport">
            <option value="udp">UDP</option>
            <option value="tcp">TCP</option>
          </select>
          @error('transport') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <div class="form-group md:col-span-2">
          <label class="form-label">DNS Resolver</label>
          <input type="text" class="form-input" wire:model.live="dns" placeholder="1.1.1.1">
          <p class="form-help">Separate multiple with commas (e.g. 1.1.1.1, 9.9.9.9)</p>
          @error('dns') <p class="aio-error text-red-400 text-xs">{{ $message }}</p> @enderror
        </div>

        <label class="form-check">
          <input type="checkbox" wire:model.live="enableIPv6">
          <span>Enable IPv6</span>
        </label>

        <label class="form-check">
          <input type="checkbox" wire:model.live="enableLogging">
          <span>Enable Logging</span>
        </label>

        <label class="form-check">
          <input type="checkbox" wire:model.live="enableProxy">
          <span>Enable Proxy</span>
        </label>

        <label class="form-check">
          <input type="checkbox" wire:model.live="header1">
          <span>Custom Header 1</span>
        </label>

        <label class="form-check">
          <input type="checkbox" wire:model.live="header2">
          <span>Custom Header 2</span>
        </label>
      </div>
    </section>

    <!-- ACTIONS -->
    <div class="flex items-center justify-end gap-3">
      <a href="{{ route('admin.servers.index') }}" class="btn-secondary">Cancel</a>
      <button type="submit" class="btn shadow-glow">🚀 Deploy Server</button>
    </div>
  </form>
</div>