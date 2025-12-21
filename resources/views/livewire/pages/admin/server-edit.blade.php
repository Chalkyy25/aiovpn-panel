{{-- ✏️ Editable Settings --}}
<form wire:submit.prevent="save" class="space-y-6 mt-6">

  <x-section-card title="Identity & Metadata" subtitle="Who / where is this node?">
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div>
      <label class="block text-xs text-[var(--aio-sub)] mb-1">Provider</label>
      <input type="text" wire:model.defer="provider" class="form-input w-full">
      @error('provider') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
      <label class="block text-xs text-[var(--aio-sub)] mb-1">Region</label>
      <input type="text" wire:model.defer="region" class="form-input w-full" placeholder="e.g. EU-West, UK-LON">
      @error('region') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">Country</label>
        <select wire:model.defer="country_code" class="form-select w-full uppercase">
          <option value="">Select</option>
          <option value="DE">Germany</option>
          <option value="ES">Spain</option>
          <option value="GB">United Kingdom</option>
          <option value="FR">France</option>
          <option value="NL">Netherlands</option>
          <option value="US">United States</option>
          <option value="CA">Canada</option>
          {{-- add only what you actually deploy --}}
        </select>
        @error('country_code') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>

      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">City</label>
        <input type="text" wire:model.defer="city" class="form-input w-full" placeholder="Frankfurt">
        @error('city') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
    </div>

    <div class="md:col-span-2">
      <label class="block text-xs text-[var(--aio-sub)] mb-1">Tags (CSV)</label>
      <input type="text" wire:model.defer="tags" class="form-input w-full" placeholder="premium, netflix, gaming">
      <p class="text-xs text-[var(--aio-sub)] mt-1">
        Comma or space separated. Example:
        <code>premium, netflix</code> or <code>premium netflix</code>
      </p>
      @error('tags') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-2">
      <input type="checkbox" id="enabled" wire:model.defer="enabled" class="h-4 w-4 rounded border-[var(--aio-border)]">
      <label for="enabled" class="text-sm text-[var(--aio-ink)]">Enabled</label>
    </div>
  </div>
</x-section-card>

  <x-section-card title="Network & SSH" subtitle="Connectivity and network behaviour.">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">SSH Port</label>
        <input type="number" min="1" max="65535" wire:model.defer="ssh_port" class="form-input w-full">
        @error('ssh_port') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">DNS (CSV)</label>
        <input type="text" wire:model.defer="dns" class="form-input w-full" placeholder="1.1.1.1,8.8.8.8">
        @error('dns') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">MTU</label>
        <input type="number" min="576" max="9000" wire:model.defer="mtu" class="form-input w-full">
        @error('mtu') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>

      <div class="flex items-center gap-2">
        <input type="checkbox" id="ipv6_enabled" wire:model.defer="ipv6_enabled" class="h-4 w-4 rounded border-[var(--aio-border)]">
        <label for="ipv6_enabled" class="text-sm text-[var(--aio-ink)]">IPv6 Enabled</label>
      </div>
    </div>
  </x-section-card>

  <x-section-card title="Monitoring & Maintenance" subtitle="Agent/health settings.">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2">
        <label class="block text-xs text-[var(--aio-sub)] mb-1">API Endpoint</label>
        <input type="text" wire:model.defer="api_endpoint" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="https://node-agent:9000/">
        @error('api_endpoint') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">API Token</label>
        <input type="text" wire:model.defer="api_token" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="secret…">
        @error('api_token') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>

      <div class="md:col-span-2">
        <label class="block text-xs text-[var(--aio-sub)] mb-1">Health Check Command</label>
        <input type="text" wire:model.defer="health_check_cmd" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="systemctl is-active openvpn-server@server">
        @error('health_check_cmd') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">Install Branch</label>
        <input type="text" wire:model.defer="install_branch" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="stable">
        @error('install_branch') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>

      <div class="flex items-center gap-2">
        <input type="checkbox" id="monitoring_enabled" wire:model.defer="monitoring_enabled" class="h-4 w-4 rounded border-white/20 bg-white/5">
        <label for="monitoring_enabled" class="text-sm">Monitoring Enabled</label>
      </div>
    </div>
  </x-section-card>

  <x-section-card title="Protocol Options" subtitle="OpenVPN / WireGuard tuning.">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      {{-- OpenVPN --}}
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">OpenVPN Cipher</label>
        <input type="text" wire:model.defer="ovpn_cipher" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="AES-256-GCM">
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">OpenVPN Compression</label>
        <input type="text" wire:model.defer="ovpn_compression" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2" placeholder="lz4-v2 / none">
      </div>

      {{-- WireGuard --}}
      <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs text-[var(--aio-sub)] mb-1">WG Public Key</label>
          <textarea rows="2" wire:model.defer="wg_public_key" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 font-mono text-xs"></textarea>
        </div>
        <div>
          <label class="block text-xs text-[var(--aio-sub)] mb-1">WG Private Key</label>
          <textarea rows="2" wire:model.defer="wg_private_key" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 font-mono text-xs"></textarea>
        </div>
      </div>
    </div>
  </x-section-card>

  <x-section-card title="Limits & Policy" subtitle="Protect capacity & shape traffic.">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">Max Clients</label>
        <input type="number" min="1" max="65000" wire:model.defer="max_clients" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2">
        @error('max_clients') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div>
        <label class="block text-xs text-[var(--aio-sub)] mb-1">Rate Limit (Mbps)</label>
        <input type="number" min="1" max="10000" wire:model.defer="rate_limit_mbps" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2">
        @error('rate_limit_mbps') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" id="allow_split_tunnel" wire:model.defer="allow_split_tunnel" class="h-4 w-4 rounded border-white/20 bg-white/5">
        <label for="allow_split_tunnel" class="text-sm">Allow Split Tunneling</label>
      </div>
    </div>
  </x-section-card>

  <x-section-card title="Notes">
    <textarea rows="3" wire:model.defer="notes" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2"></textarea>
  </x-section-card>

  <div class="flex items-center justify-end gap-2">
    <a href="{{ route('admin.servers.index') }}" class="aio-pill bg-white/10">Cancel</a>
    <button type="submit" class="aio-pill pill-neon hover:shadow-glow">💾 Save Changes</button>
  </div>

</form>