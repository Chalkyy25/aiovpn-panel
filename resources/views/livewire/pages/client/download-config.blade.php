<div class="max-w-4xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">Download VPN Configurations</h1>

    @if (session()->has('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif

    @if (session()->has('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6">
        @foreach($configs as $config)
            <div class="bg-white rounded-lg shadow p-6 border">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-semibold">{{ $config['server_name'] }}</h3>
                        <p class="text-sm text-gray-600">{{ $config['description'] ?? '' }}</p>
                        
                        @if($config['priority'] === 1)
                            <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full mt-1">
                                ⭐ RECOMMENDED
                            </span>
                        @endif
                    </div>
                    
                    <div class="flex flex-col gap-2">
                        @if($config['variant'] === 'wireguard')
                            <button 
                                wire:click="downloadConfig({{ $config['server_id'] }}, 'wireguard')"
                                class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm">
                                📱 Download WireGuard
                            </button>
                        @else
                            <button 
                                wire:click="downloadConfig({{ $config['server_id'] }}, '{{ $config['variant'] }}')"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                                @if($config['variant'] === 'unified')
                                    🛡️ Download Smart Profile
                                @elseif($config['variant'] === 'stealth')
                                    🥷 Download Stealth (TCP 443)
                                @else
                                    📡 Download Traditional (UDP)
                                @endif
                            </button>
                        @endif
                    </div>
                </div>

                <div class="text-sm text-gray-500">
                    <strong>File:</strong> {{ $config['filename'] }}
                </div>

                @if($config['variant'] === 'unified')
                    <div class="mt-2 p-3 bg-blue-50 rounded text-sm">
                        <strong>Smart Profile:</strong> Automatically tries TCP 443 (stealth) first, then falls back to UDP if blocked. Best for bypassing ISP restrictions.
                    </div>
                @elseif($config['variant'] === 'stealth')
                    <div class="mt-2 p-3 bg-green-50 rounded text-sm">
                        <strong>Stealth Mode:</strong> Uses TCP port 443 to appear as HTTPS traffic. Excellent for bypassing firewalls and ISP blocks.
                    </div>
                @elseif($config['variant'] === 'udp')
                    <div class="mt-2 p-3 bg-yellow-50 rounded text-sm">
                        <strong>Traditional Mode:</strong> Standard UDP connection. Fastest option but may be blocked by some ISPs.
                    </div>
                @elseif($config['variant'] === 'wireguard')
                    <div class="mt-2 p-3 bg-purple-50 rounded text-sm">
                        <strong>WireGuard:</strong> Modern VPN protocol. Very fast and secure. Requires WireGuard-compatible app.
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if(empty($configs))
        <div class="text-center py-8">
            <p class="text-gray-500">No VPN servers assigned to your account.</p>
            <p class="text-sm text-gray-400 mt-2">Contact your administrator to get access to VPN servers.</p>
        </div>
    @endif
</div>
