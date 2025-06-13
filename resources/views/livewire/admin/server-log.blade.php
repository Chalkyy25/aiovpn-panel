<div wire:poll.2s>
    <div class="mb-2">
        <span class="font-semibold">Status:</span>
        <span class="px-2 py-1 rounded bg-gray-200 text-gray-700">
            {{ $status }}
        </span>
    </div>
    <div class="bg-black text-green-400 p-2 rounded h-64 overflow-auto font-mono text-sm whitespace-pre-line">
        {{ $log ?: 'No logs yet...' }}
    </div>
</div>
<div class="mt-4">
    <button wire:click="clearLog" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
        Clear Log
    </button>
    <button wire:click="refreshLog" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 ml-2">
        Refresh Log
    </button>
    <button wire:click="downloadLog" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 ml-2">
        Download Log   
    </button>
   
    <button
    wire:click="redeploy"
    class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700 mt-2"
>
    Redeploy
</button>

</div>
