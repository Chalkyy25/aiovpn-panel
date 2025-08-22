<div
    wire:poll.2s
    x-data="{ log: '' }"
    x-init="$watch('log', () => $el.scrollTop = $el.scrollHeight)"
    class="bg-black text-green-400 font-mono text-sm p-4 rounded-lg shadow max-h-[400px] overflow-y-auto leading-relaxed"
>
    <div class="text-white text-base font-semibold mb-2">💻 Live Terminal</div>
    <pre class="whitespace-pre-wrap">{{ $log }}</pre>
    <pre class="whitespace-pre-wrap relative">{{ $log }}<span class="animate-pulse absolute">█</span></pre>
x-init="$watch('log', () => $el.scrollTop = $el.scrollHeight)"
</div>
<script>
    setInterval(() => {
        Livewire.emit('refreshLog');
    }, 3000);
</script>
