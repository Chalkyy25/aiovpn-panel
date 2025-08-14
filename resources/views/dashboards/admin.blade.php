@php
    use App\Models\VpnUser;

    // The route passes:
    // totalUsers, activeUsers, totalVpnUsers, totalResellers, totalClients, activeVpnUsers
    // Provide safe defaults if any key is missing.
    $m = [
        'totalUsers'     => $totalUsers     ?? 0,
        'activeUsers'    => $activeUsers    ?? 0,
        'totalVpnUsers'  => $totalVpnUsers  ?? 0,
        'activeVpnUsers' => $activeVpnUsers ?? 0,
        'totalResellers' => $totalResellers ?? 0,
        'totalClients'   => $totalClients   ?? 0,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">VPN Dashboard</h1>
                <p class="text-gray-500 dark:text-gray-400">Real‑time overview of your panel</p>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Last updated: {{ now()->format('H:i:s') }}
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- STAT CARDS (circled style) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <x-stat-card title="Users (Total)"       :value="$m['totalUsers']"     icon="o-user-group" />
                <x-stat-card title="Active Users"        :value="$m['activeUsers']"    icon="o-rectangle-stack" />
                <x-stat-card title="VPN Users"           :value="$m['totalVpnUsers']"  icon="o-server" />
                <x-stat-card title="Active VPN Users"    :value="$m['activeVpnUsers']" icon="o-bolt" hint="have servers" />
            </div>

            {{-- OPTIONAL: a second row (uncomment if you want 6 stats visible) --}}
             <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <x-stat-card title="Resellers" :value="$m['totalResellers']" icon="o-briefcase" />
                <x-stat-card title="Clients"   :value="$m['totalClients']"   icon="o-user" />
            </div>

            {{-- SERVERS (put your tabs/filters here if you like) --}}
            <x-section-card title="Servers">
                {{ $serverTabs ?? '' }}
            </x-section-card>

            {{-- ACTIVE CONNECTIONS (if you render a table from Livewire or controller) --}}
            @isset($connectionsTable)
                <div class="mt-6">
                    <x-section-card title="Active Connections">
                        {{ $connectionsTable }}
                    </x-section-card>
                </div>
            @endisset

            {{-- Helper table from your original view --}}
            <div class="mt-6">
                <x-section-card title="VPN Users">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="border-b">
                                <tr>
                                    <th class="px-4 py-2 text-left">Username</th>
                                    <th class="px-4 py-2 text-left">Password</th>
                                    <th class="px-4 py-2 text-left">Servers Assigned</th>
                                    <th class="px-4 py-2 text-left">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(VpnUser::with('vpnServers')->latest('id')->limit(10)->get() as $user)
                                    <tr class="border-b">
                                        <td class="px-2 py-1">{{ $user->username }}</td>
                                        <td class="px-4 py-2">
                                            @if ($user->plain_password)
                                                {{ $user->plain_password }}
                                            @else
                                                <span class="text-gray-400 italic">N/A</span>
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
                                                <span class="text-gray-500">No servers assigned</span>
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