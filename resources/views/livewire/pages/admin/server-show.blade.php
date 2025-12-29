@php use Illuminate\Support\Str; @endphp

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <h2 class="text-xl font-semibold text-[var(--aio-header)]">
        Server Status: {{ $vpnServer->name }}
    </h2>
    <div class="flex flex-wrap gap-2">
        <x-button
            wire:click="deployServer"
            onclick="return confirm('Are you sure you want to redeploy this server?')"
            class="aio-pill pill-cya hover:shadow-glow"
        >🚀 Install / Re-Deploy</x-button>

        <x-button
            wire:click="restartVpn"
            onclick="return confirm('Are you sure you want to restart the VPN?')"
            class="aio-pill pill-mag hover:shadow-glow"
        >🔁 Restart VPN</x-button>

        <x-button
            wire:click="deleteServer"
            onclick="return confirm('Are you sure you want to DELETE this server? This cannot be undone.')"
            class="aio-pill bg-red-500/20 text-red-400 hover:shadow-glow"
        >🗑️ Delete</x-button>

        <x-button
            wire:click="generateConfig"
            class="aio-pill pill-neon hover:shadow-glow"
        >📅 Client Config</x-button>
    </div>
</div>

{{-- 📝 Basic details --}}
<div class="aio-card p-4 sm:p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">Details</h3>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-2 text-sm">
        <dt class="font-medium text-[var(--aio-sub)]">IP:</dt>
        <dd class="text-[var(--aio-ink)]">{{ $vpnServer->ip_address }}</dd>

        <dt class="font-medium text-[var(--aio-sub)]">Protocol:</dt>
        <dd class="capitalize text-[var(--aio-ink)]">{{ $vpnServer->protocol }}</dd>

        <dt class="font-medium text-[var(--aio-sub)]">SSH User:</dt>
        <dd class="text-[var(--aio-ink)]">{{ $vpnServer->ssh_user }}</dd>

        <dt class="font-medium text-[var(--aio-sub)]">VPN Port:</dt>
        <dd class="text-[var(--aio-ink)]">{{ $vpnServer->port }}</dd>

        <dt class="font-medium text-[var(--aio-sub)]">Status:</dt>
        <dd>
            @php
                $colour = [
                    'queued'    => 'aio-pill bg-white/10 text-[var(--aio-sub)]',
                    'running'   => 'aio-pill pill-mag',
                    'succeeded' => 'aio-pill pill-neon',
                    'failed'    => 'aio-pill bg-red-500/20 text-red-400',
                ][$vpnServer->deployment_status] ?? 'aio-pill bg-white/10 text-[var(--aio-sub)]';
            @endphp
            <span class="{{ $colour }}">
                {{ ucfirst($vpnServer->deployment_status) }}
            </span>
        </dd>
    </dl>
</div>

{{-- 📦 Deployment log --}}
<div wire:poll.3s="refresh" class="aio-card p-4 sm:p-6 mb-6">
    <h3 class="text-lg font-bold mb-4">Deployment Logs</h3>
    <div id="deploy-log"
         style="max-height: 300px; overflow-y: auto; background: #0b0f1a; color: var(--aio-sub); font-family: monospace; padding: 1em; border-radius: 8px;"
         class="overflow-x-auto text-xs">
        @foreach($this->filteredLog as $entry)
            <div class="{{ $entry['color'] }}">{{ $entry['text'] }}</div>
        @endforeach
    </div>
</div>

<script>
    document.addEventListener('livewire:init', function () {
        Livewire.hook('commit', () => {
            let logDiv = document.getElementById('deploy-log');
            if (logDiv) logDiv.scrollTop = logDiv.scrollHeight;
        });
    });
</script>

{{-- 📊 Live monitoring --}}
<div class="aio-card p-4 sm:p-6">
    <h3 class="text-lg font-bold mb-4">Live Monitoring</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">

        {{-- Uptime --}}
        <div class="aio-card p-3">
            <span class="text-[var(--aio-cya)]">🕒</span>
            <span class="font-medium text-[var(--aio-ink)]">Uptime:</span>
            <div class="mt-1 text-[var(--aio-ink)]">
                {{ trim(Str::before($uptime, 'load average')) ?: 'No data' }}
            </div>
            <div class="text-xs text-[var(--aio-sub)]">
                Load: {{ trim(Str::after($uptime, 'load average:')) ?: 'No data' }}
            </div>
        </div>

        {{-- CPU --}}
        <div class="aio-card p-3">
            <span class="text-[var(--aio-mag)]">🧠</span>
            <span class="font-medium text-[var(--aio-ink)]">CPU:</span>
            <div class="mt-1 text-[var(--aio-ink)]">
                @php
                    preg_match('/([\d\.]+) us, ([\d\.]+) sy, [\d\.]+ ni, ([\d\.]+) id/', $cpu, $matches);
                @endphp
                Usage:
                <span class="font-semibold">{{ $matches[1] ?? '?' }}%</span> user,
                <span class="font-semibold">{{ $matches[2] ?? '?' }}%</span> system,
                <span class="font-semibold">{{ $matches[3] ?? '?' }}%</span> idle
            </div>
        </div>

        {{-- Memory --}}
        <div class="aio-card p-3">
            <span class="text-[var(--aio-pup)]">📀</span>
            <span class="font-medium text-[var(--aio-ink)]">Memory:</span>
            <div class="mt-1 text-[var(--aio-ink)]">
                @php
                    preg_match('/Mem:\s*([\d\.]+\w*)\s*([\d\.]+\w*)\s*([\d\.]+\w*)/', $memory, $mem);
                @endphp
                Total: <span class="font-semibold">{{ $mem[1] ?? '?' }}</span>,
                Used: <span class="font-semibold">{{ $mem[2] ?? '?' }}</span>,
                Free: <span class="font-semibold">{{ $mem[3] ?? '?' }}</span>
            </div>
        </div>

        {{-- Bandwidth --}}
        <div class="aio-card p-3">
            <span class="text-[var(--aio-neon)]">🌐</span>
            <span class="font-medium text-[var(--aio-ink)]">Bandwidth:</span>
            <div class="mt-1 text-[var(--aio-ink)]">
                @php
                    $bw = explode(';', str_replace([',', "\n"], ['.', ''], $bandwidth));
                @endphp
                Interface: <span class="font-semibold">{{ $bw[0] ?? '?' }}</span><br>
                Today: <span class="font-semibold">{{ $bw[2] ?? '?' }}</span> transferred<br>
                Rate: <span class="font-semibold">{{ $bw[5] ?? '?' }}</span>
            </div>
        </div>
    </div>
</div>
