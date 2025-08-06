<div class="max-w-3xl mx-auto p-6">
    <h2 class="text-xl font-semibold mb-4">🚀 Deployment Status: {{ $vpnServer->name }}</h2>
    <div class="mb-4">
        <span class="inline-block px-3 py-1 rounded
            @if($deploymentStatus === 'succeeded') bg-green-200 text-green-800
            @elseif($deploymentStatus === 'failed') bg-red-200 text-red-800
            @elseif($deploymentStatus === 'running') bg-yellow-200 text-yellow-800
            @else bg-gray-200 text-gray-800 @endif
        ">
            Status: {{ ucfirst($deploymentStatus) ?: 'Unknown' }}
        </span>
    </div>
<div
    wire:poll.2s="refreshStatus"
    class="bg-black rounded-lg p-4 overflow-y-auto"
    style="min-height: 250px; max-height: 400px; font-family: monospace;"
    id="deployment-log"
>
    @forelse($this->filteredLog as $entry)
        <div class="{{ $entry['color'] }}">{{ $entry['text'] }}</div>
    @empty
        <div class="text-gray-400">No deployment logs found.</div>
    @endforelse
</div>
</div>

<script>
    document.addEventListener('livewire:load', function () {
        Livewire.hook('message.processed', (message, component) => {
            let logDiv = document.getElementById('deployment-log');
            if (logDiv) logDiv.scrollTop = logDiv.scrollHeight;
        });
    });
</script>
