@php use Illuminate\Support\Str; @endphp

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <h2 class="text-xl font-semibold text-gray-800">
        Server Status: {{ $vpnServer->name }}
    </h2>
    <div class="flex flex-wrap gap-2">
        <x-button
            wire:click="deployServer"
            onclick="return confirm('Are you sure you want to redeploy this server?')"
            class="bg-blue-600 text-white"
        >🚀 Install / Re-Deploy</x-button>
        <x-button
            wire:click="restartVpn"
            onclick="return confirm('Are you sure you want to restart the VPN?')"
            class="bg-yellow-500 text-white"
        >🔁 Restart VPN</x-button>
        <x-button
            wire:click="deleteServer"
            onclick="return confirm('Are you sure you want to DELETE this server? This cannot be undone.')"
            class="bg-red-600 text-white"
        >🗑️ Delete</x-button>
        <x-button
            wire:click="generateConfig"
            class="bg-black text-white"
        >📅 Client Config</x-button>
    </div>
</div>

<div 
    {{ $deploymentStatus !== 'succeeded' && $deploymentStatus !== 'failed' ? 'wire:poll.2s=refresh' : '' }} 
    class="..."
>
    {{-- 📝 Basic details --}}
    <div class="bg-white p-4 sm:p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Details</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-1 text-sm">
            <dt class="font-medium text-gray-600">IP:</dt>
            <dd>{{ $vpnServer->ip_address }}</dd>
            <dt class="font-medium text-gray-600">Protocol:</dt>
            <dd class="capitalize">{{ $vpnServer->protocol }}</dd>
            <dt class="font-medium text-gray-600">SSH User:</dt>
            <dd>{{ $vpnServer->ssh_user }}</dd>
            <dt class="font-medium text-gray-600">VPN&nbsp;Port:</dt>
            <dd>{{ $vpnServer->port }}</dd>
            <dt class="font-medium text-gray-600">Status:</dt>
            <dd>
                @php
                    $colour = [
                        'queued'    => 'bg-gray-200 text-gray-700',
                        'running'   => 'bg-yellow-200 text-yellow-800',
                        'succeeded' => 'bg-green-200 text-green-800',
                        'failed'    => 'bg-red-200 text-red-800',
                    ][$vpnServer->deployment_status] ?? 'bg-gray-200 text-gray-700';
                @endphp
                <span class="px-2 py-0.5 rounded text-xs {{ $colour }}">
                    {{ ucfirst($vpnServer->deployment_status) }}
                </span>
            </dd>
        </dl>
    </div>

    {{-- 📦 Deployment log --}}
    <div class="bg-white p-4 sm:p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Deployment&nbsp;Logs</h3>
        <div id="deploy-log"
             style="max-height: 300px; overflow-y: auto; background: #181818; color: #eee; font-family: monospace; padding: 1em; border-radius: 8px;"
             class="overflow-x-auto text-xs"
            @foreach($this->filteredLog as $entry)
                <div class="{{ $entry['color'] }}">{{ $entry['text'] }}</div>
            @endforeach
        </div>
    </div>

    <script>
        document.addEventListener('livewire:load', function () {
            Livewire.hook('message.processed', (message, component) => {
                let logDiv = document.getElementById('deploy-log');
                if (logDiv) logDiv.scrollTop = logDiv.scrollHeight;
            });
        });
    </script>

    {{-- 📊 Live monitoring --}}
    <div class="bg-white p-4 sm:p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Live Monitoring</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            {{-- Uptime --}}
            <div class="bg-blue-50 rounded p-3">
                <span class="text-blue-600">🕒</span>
                <span class="font-medium text-gray-700">Uptime:</span>
                <div class="mt-1 text-gray-800">
                    {{ trim(Str::before($uptime, 'load average')) ?: 'No data' }}
                </div>
                <div class="text-xs text-gray-500">
                    Load: {{ trim(Str::after($uptime, 'load average:')) ?: 'No data' }}
                </div>
            </div>
            {{-- CPU --}}
            <div class="bg-purple-50 rounded p-3">
                <span class="text-purple-600">🧠</span>
                <span class="font-medium text-gray-700">CPU:</span>
                <div class="mt-1 text-gray-800">
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
            <div class="bg-indigo-50 rounded p-3">
                <span class="text-indigo-600">📀</span>
                <span class="font-medium text-gray-700">Memory:</span>
                <div class="mt-1 text-gray-800">
                    @php
                        preg_match('/Mem:\s*([\d\.]+\w*)\s*([\d\.]+\w*)\s*([\d\.]+\w*)/', $memory, $mem);
                    @endphp
                    Total: <span class="font-semibold">{{ $mem[1] ?? '?' }}</span>,
                    Used: <span class="font-semibold">{{ $mem[2] ?? '?' }}</span>,
                    Free: <span class="font-semibold">{{ $mem[3] ?? '?' }}</span>
                </div>
            </div>
            {{-- Bandwidth --}}
            <div class="bg-teal-50 rounded p-3">
                <span class="text-teal-600">🌐</span>
                <span class="font-medium text-gray-700">Bandwidth:</span>
                <div class="mt-1 text-gray-800">
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
</div>


