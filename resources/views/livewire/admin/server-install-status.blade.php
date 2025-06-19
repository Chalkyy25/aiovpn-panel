<div wire:poll.8000ms="checkStatus" class="max-w-4xl mx-auto p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-semibold mb-4">Server Install Status: {{ $server->name }}</h2>

    {{-- Status box --}}
    @if ($server->status === 'Online')
        <div class="bg-green-100 text-green-800 p-4 rounded mb-4">✅ Server is Online</div>
    @elseif ($server->status === 'Installing')
        <div class="bg-yellow-100 text-yellow-800 p-4 rounded mb-4">🔄 Installing...</div>
    @elseif ($server->status === 'Error')
        <div class="bg-red-100 text-red-800 p-4 rounded mb-4">❌ Installation Failed</div>
    @endif

    {{-- Logs --}}
    <div class="bg-black text-green-400 text-sm font-mono p-4 rounded overflow-y-auto max-h-[400px] md:max-h-[600px]" style="white-space:pre-wrap;">
        {{ $server->install_log ?: '⏳ Waiting for logs...' }}
    </div>

    {{-- View Server button once it's done --}}
    @if ($server->status === 'Online')
        <div class="mt-6">
            <a href="{{ route('admin.servers.edit', $server->id) }}" class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                View Server
            </a>
        </div>
    @endif
</div>
