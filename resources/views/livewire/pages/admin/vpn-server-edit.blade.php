<div class="max-w-5xl mx-auto space-y-6" wire:poll.30s="refreshQuickFacts">

  {{-- Header --}}
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">Edit Server</h1>
      <p class="text-sm text-[var(--aio-sub)]">ID #{{ $server->id }} • Last updated {{ optional($server->updated_at)->diffForHumans() }}</p>
    </div>

    <div class="flex items-center gap-2">
      <x-button wire:click="testConnection" class="aio-pill pill-cya hover:shadow-glow">🔌 Test Connection</x-button>
      <x-button wire:click="syncNow" class="aio-pill pill-pup hover:shadow-glow">🔁 Sync</x-button>
      <a href="{{ route('admin.servers.show', $server->id) }}" class="aio-pill pill-neon hover:shadow-glow inline-flex items-center">🔍 View</a>
    </div>
  </div>

  {{-- Quick facts / status --}}
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <x-stat-card title="Status" :value="$server->status ? Str::upper($server->status) : 'N/A'" icon="o-signal" :variant="$server->status === 'online' ? 'neon' : 'mag'" compact />
    <x-stat-card title="Protocol" :value="Str::upper($protocol)" icon="o-server" variant="cya" compact />
    <x-stat-card title="Port" :value="$port" icon="o-arrow-right-circle" variant="pup" compact />
  </div>

  {{-- Form --}}
  <form wire:submit.prevent="save" class="space-y-6">
    {{-- Identity --}}
    <x-section-card title="Identity" subtitle="How this server appears in your panel.">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <x-label for="name" value="Name" />
          <input id="name" type="text" wire:model.defer="name"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
          @error('name') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="region" value="Region/Tag (optional)" />
          <input id="region" type="text" wire:model.defer="region"
                 placeholder="e.g. UK-LON"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
          @error('region') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
      </div>
    </x-section-card>

    {{-- Network --}}
    <x-section-card title="Network" subtitle="How clients connect to this node.">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <x-label for="ip_address" value="Public IP / Host" />
          <input id="ip_address" type="text" wire:model.defer="ip_address"
                 placeholder="1.2.3.4 or host.example.com"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
          @error('ip_address') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="protocol" value="Protocol" />
          <select id="protocol" wire:model.live="protocol"
                  class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
            <option value="openvpn">OpenVPN</option>
            <option value="wireguard">WireGuard</option>
          </select>
          @error('protocol') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="port" value="Port" />
          <input id="port" type="number" min="1" max="65535" wire:model.defer="port"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
          @error('port') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
      </div>
    </x-section-card>

    {{-- SSH --}}
    <x-section-card title="SSH Access" subtitle="Used for provisioning, log reads, and health checks.">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <x-label for="ssh_user" value="SSH User" />
          <input id="ssh_user" type="text" wire:model.defer="ssh_user"
                 placeholder="root"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-mag)]">
          @error('ssh_user') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="ssh_port" value="SSH Port" />
          <input id="ssh_port" type="number" min="1" max="65535" wire:model.defer="ssh_port"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--aio-mag)]">
          @error('ssh_port') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div x-data="{show:false}">
          <x-label for="ssh_key" value="SSH Private Key" />
          <div class="relative">
            <textarea id="ssh_key" rows="4" wire:model.defer="ssh_key"
                      :type="show ? 'text' : 'password'"
                      class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-[var(--aio-mag)]"
                      placeholder="-----BEGIN OPENSSH PRIVATE KEY----- ..."></textarea>
            <button type="button" @click="show=!show"
                    class="absolute right-2 top-2 aio-pill bg-white/10 text-[var(--aio-ink)] text-[10px]">Toggle</button>
          </div>
          @error('ssh_key') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
      </div>
      <p class="text-xs text-[var(--aio-sub)] mt-2">We strongly recommend key‑based auth. Password auth should be disabled in SSHD.</p>
    </x-section-card>

    {{-- Advanced --}}
    <x-section-card title="Advanced" subtitle="Optional overrides & metadata.">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <x-label for="dns" value="DNS (CSV)" />
          <input id="dns" type="text" wire:model.defer="dns"
                 placeholder="1.1.1.1, 8.8.8.8"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
          @error('dns') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="mtu" value="MTU" />
          <input id="mtu" type="number" min="576" max="9000" wire:model.defer="mtu"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
          @error('mtu') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
          <x-label for="notes" value="Notes" />
          <input id="notes" type="text" wire:model.defer="notes"
                 class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--aio-pup)]">
          @error('notes') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>
      </div>
    </x-section-card>

    {{-- Actions --}}
    <div class="flex items-center justify-between">
      <div class="text-xs text-[var(--aio-sub)]">Tip: <kbd class="aio-pill bg-white/10">Ctrl / Cmd + S</kbd> to save.</div>
      <div class="flex gap-2">
        <x-button type="button" wire:click="deployServer" class="aio-pill pill-neon hover:shadow-glow">🚀 Deploy</x-button>
        <x-button type="submit" class="aio-pill pill-cya hover:shadow-glow">💾 Save Changes</x-button>
      </div>
    </div>

    {{-- Danger Zone --}}
    <x-section-card title="Danger Zone" subtitle="Irreversible actions." class="border-red-500/30">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-red-300">Delete will remove this node from your panel. Configs and users attached won’t be removed from devices.</p>
        <x-button wire:click="deleteServer"
                  onclick="return confirm('Are you sure? This cannot be undone.')"
                  class="aio-pill bg-red-500/20 text-red-400 hover:shadow-glow">
          🗑️ Delete Server
        </x-button>
      </div>
    </x-section-card>
  </form>

  {{-- Save toast --}}
  @if (session()->has('saved'))
    <div class="fixed bottom-4 right-4 aio-pill pill-neon shadow-glow">{{ session('saved') }}</div>
  @endif
</div>

@push('scripts')
<script>
document.addEventListener('keydown', (e) => {
  const meta = e.ctrlKey || e.metaKey;
  if (meta && e.key.toLowerCase() === 's') {
    e.preventDefault();
    window.Livewire?.find(@this.__instance.id)?.save();
  }
});
</script>
@endpush