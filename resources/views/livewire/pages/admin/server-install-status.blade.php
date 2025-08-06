<div class="max-w-3xl mx-auto p-6">
    <h2 class="text-xl font-semibold mb-4">🚀 Deployment Status: {{ $vpnServer->name }}</h2>
    <div class="mb-4">
        @php
            // Separate background and text colors to avoid duplicate CSS properties
            $bgColor = match($deploymentStatus) {
                'succeeded' => 'bg-green-200',
                'failed' => 'bg-red-200',
                'running' => 'bg-yellow-200',
                default => 'bg-gray-200'
            };
            // Use a single text color
            $textColor = 'text-gray-800';
        @endphp
        <span class="inline-block px-3 py-1 rounded {{ $bgColor }} {{ $textColor }}">
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
    document.addEventListener('livewire:init', function () {
            Livewire.hook('commit', () => {
            let logDiv = document.getElementById('deployment-log');
            if (logDiv) logDiv.scrollTop = logDiv.scrollHeight;
        });
    });
</script>
