<div wire:poll.5s="refreshStatus" class="max-w-4xl mx-auto p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-semibold mb-4">Server Install Status: {{ $vpnServer->name }}</h2>

    {{-- Status box --}}
    @if ($vpnServer->deployment_status === 'succeeded')
        <div class="bg-green-100 text-green-800 p-4 rounded mb-4">✅ Server is Online</div>
    @elseif ($vpnServer->deployment_status === 'running')
        <div class="bg-yellow-100 text-yellow-800 p-4 rounded mb-4">🔄 Installing...</div>
    @elseif ($vpnServer->deployment_status === 'failed')
        <div class="bg-red-100 text-red-800 p-4 rounded mb-4">❌ Installation Failed</div>
    @endif

    {{-- Logs --}}
    <div class="bg-black text-green-400 text-sm font-mono p-4 rounded overflow-y-auto max-h-[400px] md:max-h-[600px]" style="white-space:pre-wrap;">
        {{ $vpnServer->deployment_log ?: '⏳ Waiting for logs...' }}
    </div>

    {{-- View Server button once it’s done --}}
    @if ($vpnServer->deployment_status === 'succeeded')
        <div class="mt-6">
            <a href="{{ route('admin.servers.edit', $vpnServer->id) }}" class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                View Server
            </a>
        </div>
    @endif

    {{-- Retry if failed --}}
    @if ($vpnServer->deployment_status === 'failed')
        <div class="mt-6">
            <button wire:click="retryInstallation" class="inline-block bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
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
 