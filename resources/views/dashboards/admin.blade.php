@php
    use App\Models\VpnUser;

    $m = [
        'totalUsers'      => $totalUsers      ?? 0,
        'activeUsers'     => $activeUsers     ?? 0,
        'totalVpnUsers'   => $totalVpnUsers   ?? 0,
        'activeVpnUsers'  => $activeVpnUsers  ?? 0,
        'totalResellers'  => $totalResellers  ?? 0,
        'totalClients'    => $totalClients    ?? 0,
        'totalServers'    => $totalServers    ?? 0,
        'onlineServers'   => $onlineServers   ?? 0,
        'offlineServers'  => $offlineServers  ?? 0,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="aio-header px-4 py-3 flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold">VPN Dashboard</h1>
                <p class="text-[var(--aio-sub)]">Real‑time overview of your panel</p>
            </div>
            <div class="text-sm text-[var(--aio-sub)]">
                Last updated: {{ now()->format('H:i:s') }}
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- USERS (row 1) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <x-stat-card title="Users (Total)"    :value="$m['totalUsers']"     icon="o-user-group"      variant="cya"  />
                <x-stat-card title="Active Users"     :value="$m['activeUsers']"    icon="o-rectangle-stack" variant="neon" />
                <x-stat-card title="VPN Users"        :value="$m['totalVpnUsers']"  icon="o-server"          variant="pup"  />
                <x-stat-card title="Active VPN Users" :value="$m['activeVpnUsers']" icon="o-bolt" hint="live" variant="mag" />
            </div>

            {{-- SERVERS (row 2) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <x-stat-card title="Servers (Total)"  :value="$m['totalServers']"   icon="o-server"         variant="pup"  />
                <x-stat-card title="Online Servers"   :value="$m['onlineServers']"  icon="o-check-circle"   variant="neon" hint="responding" />
                <x-stat-card title="Offline Servers"  :value="$m['offlineServers']" icon="o-x-circle"       variant="mag"  hint="not reachable" />
                {{-- (optional) add average time/throughput here --}}
            </div>

            {{-- SERVERS SECTION (content/tabs go here) --}}
            <x-section-card title="Servers">
                {{ $serverTabs ?? '' }}
            </x-section-card>

            {{-- ACTIVE CONNECTIONS (if provided) --}}
            @isset($connectionsTable)
                <div class="mt-6">
                    <x-section-card title="Active Connections">
                        {{ $connectionsTable }}
                    </x-section-card>
                </div>
            @endisset

            {{-- Helper table --}}
            <div class="mt-6">
                <x-section-card title="VPN Users">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="border-b aio-divider">
                                <tr>
                                    <th class="px-4 py-2 text-left">Username</th>
                                    <th class="px-4 py-2 text-left">Password</th>
                                    <th class="px-4 py-2 text-left">Servers Assigned</th>
                                    <th class="px-4 py-2 text-left">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(VpnUser::with('vpnServers')->latest('id')->limit(10)->get() as $user)
                                    <tr class="border-b aio-divider">
                                        <td class="px-2 py-1">{{ $user->username }}</td>
                                        <td class="px-4 py-2">
                                            @if ($user->plain_password)
                                                {{ $user->plain_password }}
                                            @else
                                                <span class="text-[var(--aio-sub)] italic">N/A</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($user->vpnServers->isNotEmpty())
                                                <ul class="list-disc ml-4">
                                                    @foreach ($user->vpnServers as $server)
                                                        <li>{{ $server->name }} ({{ $server->ip_address }})</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <span class="text-[var(--aio-sub)]">No servers assigned</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">{{ optional($user->created_at)->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-section-card>
            </div>

        </div>
    </div>
</x-app-layout>