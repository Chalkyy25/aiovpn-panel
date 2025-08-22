<h2 class="text-xl font-bold text-white mb-4">Server Details</h2>
<p><strong>Name:</strong> {{ $vpnServer->name }}</p>
<p><strong>IP:</strong> {{ $vpnServer->ip }}</p>
<p><strong>Status:</strong> {{ ucfirst($vpnServer->deployment_status) }}</p>
<p class="mt-4">
    <span class="font-semibold text-white">Status:</span>
    @php
        $status = strtolower($vpnServer->deployment_status);
        $badgeColors = [
            'pending' => 'bg-yellow-500',
            'running' => 'bg-blue-500',
            'success' => 'bg-green-500',
            'failed'  => 'bg-red-500',
        ];
    @endphp
    <span class="px-3 py-1 rounded text-white text-sm {{ $badgeColors[$status] ?? 'bg-gray-600' }}">
        {{ ucfirst($status) }}
    </span>
</p>
<hr class="my-4">

<!-- ✅ Livewire Deployment Log Component -->
<livewire:terminal-output :server="$vpnServer" />
<livewire:test-box />
