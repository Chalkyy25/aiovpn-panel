<div wire:poll.5s="refreshStatus" class="max-w-4xl mx-auto p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-semibold mb-4">
        Server Install Status: {{ $vpnServer->name }}
    </h2>

    {{-- Status box --}}
    @if ($deploymentStatus === 'succeeded')
        <div class="bg-green-100 text-green-800 p-4 rounded mb-4">✅ Server is Online</div>
    @elseif ($deploymentStatus === 'running')
        <div class="bg-yellow-100 text-yellow-800 p-4 rounded mb-4">🔄 Installing...</div>
    @elseif ($deploymentStatus === 'failed')
        <div class="bg-red-100 text-red-800 p-4 rounded mb-4">❌ Installation Failed</div>
    @endif

    <div>Status: [{{ $deploymentStatus }}]</div>

    {{-- Logs --}}
    <div class="bg-black text-green-400 font-mono p-4 rounded mb-4 h-64 overflow-y-auto text-xs">
        @php
            use Illuminate\Support\Str;
        @endphp
        @foreach(explode("\n", $deploymentLog) as $line)
            @if(Str::contains($line, '❌'))
                <div class="text-red-400">{{ $line }}</div>
            @elseif(Str::contains($line, 'WARNING'))
                <div class="text-yellow-400">{{ $line }}</div>
            @elseif(
                preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION/', $line)
                || trim($line) === ''
            )
                {{-- skip noisy lines --}}
            @else
                <div>{{ $line }}</div>
            @endif
        @endforeach
    </div>

    {{-- View Server button once it’s done --}}
    @if ($deploymentStatus === 'succeeded')
        <div class="mt-6">
            <a href="{{ route('admin.servers.show', $server->id) }}"
               class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                View Server
            </a>
        </div>
    @endif

    {{-- Retry if failed --}}
    @if ($deploymentStatus === 'failed')
        <div class="mt-6">
            <button wire:click="retryInstallation"
                    class="inline-block bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                Retry Installation
            </button>
        </div>
    @endif
    {{-- Debug info --}}
    @if (session()->has('debug'))
        <div class="mt-6 p-4 bg-yellow-100 text-black rounded">
            {{ session('debug') }}
        </div>
    @endif

    {{-- Polling info --}}
    <div class="mt-6">
        <p class="text-sm text-gray-500">Polling for updates every 5 seconds...</p>
    </div>
</div>
