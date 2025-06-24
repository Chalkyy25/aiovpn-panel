<div>
    {{-- Header with action buttons --}}
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h2 class="text-xl font-semibold text-gray-800">
            Server Status: {{ $vpnServer->name }}
        </h2>
        <div class="flex flex-wrap gap-2">
            <x-button wire:click="deployServer" class="bg-blue-600 text-white">🚀 Install / Re-Deploy</x-button>
            <x-button wire:click="restartVpn" class="bg-yellow-500 text-white">🔁 Restart VPN</x-button>
            <x-button wire:click="deleteServer" class="bg-red-600 text-white">🗑️ Delete</x-button>
            <x-button wire:click="generateConfig" class="bg-black text-white">📅 Client Config</x-button>
        </div>
    </div>

    {{-- ...rest of your component --}}
</div>

<div wire:poll.10s="refresh" class="max-w-4xl mx-auto p-2 sm:p-6 space-y-6">

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
             wire:poll.10s="refresh">
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
        <h3 class="text-lg font-bold mb-4">Live&nbsp;Monitoring</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div class="bg-blue-50 rounded p-3">
                <span class="text-blue-600">🕒</span>
                <span class="font-medium text-gray-700">Uptime:</span>
                <span>{{ $uptime }}</span>
            </div>
            <div class="bg-purple-50 rounded p-3">
                <span class="text-purple-600">🧠</span>
                <span class="font-medium text-gray-700">CPU:</span>
                <span>{{ $cpu }}</span>
            </div>
            <div class="bg-indigo-50 rounded p-3">
                <span class="text-indigo-600">📀</span>
                <span class="font-medium text-gray-700">Memory:</span>
                <span>{{ $memory }}</span>
            </div>
            <div class="bg-teal-50 rounded p-3">
                <span class="text-teal-600">🌐</span>
                <span class="font-medium text-gray-700">Bandwidth:</span>
                <span>{{ $bandwidth }}</span>
            </div>
        </div>
    </div>                 
</div>
