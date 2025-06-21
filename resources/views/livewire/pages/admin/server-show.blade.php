<x-slot name="header">
    <h2 class="text-xl font-semibold text-gray-800">
        Server Status: {{ $server->name }}
    </h2>
</x-slot>

<div wire:poll.10s="refresh" class="max-w-4xl mx-auto p-6 space-y-6">

    {{-- ✅ Basic Info --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-2">Details</h3>
        <p><strong>IP:</strong> {{ $server->ip_address }}</p>
        <p><strong>Protocol:</strong> {{ $server->protocol }}</p>
        <p><strong>SSH User:</strong> {{ $server->ssh_user }}</p>
        <p><strong>VPN Port:</strong> {{ $server->port }}</p>
        <p><strong>Status:</strong> {{ ucfirst($server->deployment_status) }}</p>
    </div>

    {{-- 🚀 Deployment Logs --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-2">Deployment Logs</h3>
        <div class="bg-black text-green-400 font-mono text-sm p-4 rounded overflow-y-auto max-h-[300px]" style="white-space:pre-wrap;">
            {{ $server->deployment_log ?: '⏳ Waiting for logs...' }}
        </div>
    </div>

    {{-- 📊 Monitoring --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-2">Monitoring (coming soon)</h3>
        <ul class="text-sm text-gray-600 space-y-1">
             <li>🕒 Uptime: {{ $uptime }}</li>
             <li>🧠 CPU Usage: {{ $cpu }}</li>
             <li>💾 Memory: {{ $memory }}</li>
             <li>🌐 Bandwidth: {{ $bandwidth }}</li>
</ul>

        </ul>
    </div>

    {{-- 🛠️ Actions --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-2">Actions</h3>
        <div class="flex flex-wrap gap-4">
            <x-button wire:click="deployServer" wire:loading.attr="disabled" class="bg-blue-600 hover:bg-blue-700 text-white">
                🚀 Deploy
            </x-button>
            <x-button wire:click="rebootServer" wire:loading.attr="disabled" class="bg-yellow-500 hover:bg-yellow-600 text-white">
                🔄 Reboot
            </x-button>
            <x-button wire:click="deleteServer" wire:loading.attr="disabled" class="bg-red-600 hover:bg-red-700 text-white">
                🗑️ Delete
            </x-button>
        </div>

        @if($deploymentStatus)
            <div class="mt-4 text-sm text-gray-600">
                <strong>Status:</strong>
            </div>
        @endif
