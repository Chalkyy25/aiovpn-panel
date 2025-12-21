<div class="max-w-3xl mx-auto p-6">
    <h2 class="text-xl font-semibold mb-4 text-[var(--aio-ink)]">🚀 Deployment Status: {{ $vpnServer->name }}</h2>
    <div class="mb-4">
        @php
            // Separate background and text colors to avoid duplicate CSS properties
            $pillClass = match($deploymentStatus) {
                'succeeded' => 'aio-pill pill-success',
                'failed' => 'aio-pill pill-danger',
                'running' => 'aio-pill bg-yellow-500/20 text-yellow-300',
                default => 'aio-pill'
            };
        @endphp
        <span class="{{ $pillClass }}">
            Status: {{ ucfirst($deploymentStatus) ?: 'Unknown' }}
        </span>
    </div>
<div
    wire:poll.2s="refreshStatus"
    class="aio-card bg-black/90 p-4 overflow-y-auto"
    style="min-height: 250px; max-height: 400px; font-family: monospace;"
    id="deployment-log"
>
    @forelse($this->filteredLog as $entry)
        <div class="{{ $entry['color'] }}">{{ $entry['text'] }}</div>
    @empty
        <div class="text-[var(--aio-sub)]">No deployment logs found.</div>
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
