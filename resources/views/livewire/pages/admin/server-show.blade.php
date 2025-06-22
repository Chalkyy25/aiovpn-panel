<x-slot name="header">
    <h2 class="text-xl font-semibold text-gray-800">
        Server Status: {{ $vpnServer->name }}
    </h2>
</x-slot>

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
            @foreach($this->filteredLog as $line)
                @php
                    $class = '';
                    if (str_contains($line, '❌') || str_contains(strtolower($line), 'failed')) $class = 'text-red-400';
                    elseif (str_contains($line, '✅') || str_contains(strtolower($line), 'succeeded')) $class = 'text-green-400';
                    elseif (str_contains(strtolower($line), 'warning')) $class = 'text-yellow-400';
                    elseif (str_contains(strtolower($line), 'notice')) $class = 'text-blue-400';
                @endphp
                <div class="{{ $class }}">{{ $line }}</div>
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

    {{-- 🛠️  Actions --}}
    <div class="bg-white p-4 sm:p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Actions</h3>
        <div class="flex flex-col sm:flex-row flex-wrap gap-3">
            <x-button wire:click="deployServer" wire:loading.attr="disabled" class="bg-blue-600 hover:bg-blue-700 text-white">
                🚀 Deploy
            </x-button>
            <x-button wire:click="rebootServer" wire:loading.attr="disabled" class="bg-yellow-500 hover:bg-yellow-600 text-white">
                🔄 Reboot
            </x-button>
            <x-button wire:click="deleteServer" wire:loading.attr="disabled" class="bg-red-600 hover:bg-red-700 text-white">
                🗑️ Delete
            </x-button>
            <x-button wire:click="generateConfig" wire:loading.attr="disabled" class="bg-green-600 hover:bg-green-700 text-white">
                📅 Client Config
            </x-button>
        </div>
        @if (session()->has('message'))
            <p class="mt-4 text-sm text-green-700">{{ session('message') }}</p>
        @endif
    </div>
</div>
