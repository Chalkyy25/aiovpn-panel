<div class="p-6">
    <!-- Impersonation Banner -->
    @if(session()->has('impersonating_admin_id'))
        <div class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 p-4 mb-6 rounded">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium">
                            🔐 Admin Impersonation Active - You are viewing as {{ $user->username }}
                        </p>
                        <p class="text-xs">
                            Admin: {{ session('impersonating_admin_name') }}
                        </p>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <form method="POST" action="{{ route('admin.stop-impersonation') }}" class="inline">
                        @csrf
                        <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded text-sm font-medium">
                            Stop Impersonation
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <h2 class="text-2xl font-semibold mb-4">Welcome, {{ $user->username }}</h2>
    @if($user->email)
        <p class="mb-6">Your email: {{ $user->email }}</p>
    @endif

    <h3 class="text-xl mb-3">Your VPN Servers</h3>
    @if ($vpnServers->isEmpty())
        <p>You have no assigned VPN servers yet.</p>
    @else
        <div class="space-y-4">
            @foreach ($vpnServers as $server)
                <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg border">
                    <div>
                        <strong class="text-lg">{{ $server->name }}</strong>
                        @if($server->location)
                            <span class="text-gray-600"> - {{ $server->location }}</span>
                        @endif
                        <br>
                        <span class="text-sm">Status:
                            <span class="{{ $server->is_online ? 'text-green-600' : 'text-red-600' }} font-semibold">
                                {{ $server->is_online ? 'Online' : 'Offline' }}
                            </span>
                        </span>
                    </div>
                    <div class="flex space-x-2">
                        <a href="{{ route('clients.config.downloadForServer', [$user, $server]) }}"
                           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors">
                            📥 Download OpenVPN
                        </a>
                        <a href="{{ route('clients.config.download', $user) }}"
                           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors">
                            📦 Download WireGuard
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Download All Configs Button -->
        <div class="mt-6 pt-4 border-t">
            <a href="{{ route('clients.configs.downloadAll', $user) }}"
               class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-md font-medium transition-colors inline-flex items-center">
                📦 Download All Configs (ZIP)
            </a>
        </div>
    @endif
</div>
