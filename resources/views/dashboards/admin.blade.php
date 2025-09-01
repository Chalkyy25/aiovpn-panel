{{-- resources/views/admin-dashboard.blade.php --}}

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
    {{-- Header slot renders inside your layout header --}}
    <x-slot name="header">
        <div class="px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-[var(--aio-ink)]">VPN Dashboard</h1>
                <p class="text-sm text-[var(--aio-sub)]">Real-time overview of your panel</p>
            </div>
            <div class="text-xs sm:text-sm text-[var(--aio-sub)]">
                Last updated: {{ now()->format('H:i:s') }}
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- ROW 1: Users summary --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                {{-- Total Users --}}
                <x-stat-card
                    title="Users (Total)"
                    :value="$m['totalUsers']"
                    icon="o-user-group"
                    variant="cya"
                />

                {{-- Active Users (use chart bar to imply activity) --}}
                <x-stat-card
                    title="Active Users"
                    :value="$m['activeUsers']"
                    icon="o-chart-bar"
                    variant="neon"
                />

                {{-- VPN Users --}}
                <x-stat-card
                    title="VPN Users"
                    :value="$m['totalVpnUsers']"
                    icon="o-server"
                    variant="pup"
                />

                {{-- Active VPN Users (also chart icon for “live/active”) --}}
                <x-stat-card
                    title="Active VPN Users"
                    :value="$m['activeVpnUsers']"
                    icon="o-chart-bar"
                    variant="mag"
                />
            </div>

            {{-- SERVERS SECTION --}}
            <x-section-card title="Servers">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {{-- Servers total --}}
                    <x-stat-card
                        title="Servers (Total)"
                        :value="$m['totalServers']"
                        icon="o-server"
                        variant="pup"
                    />

                    {{-- Online Servers (reuse chart icon to avoid missing icon names) --}}
                    <x-stat-card
                        title="Online Servers"
                        :value="$m['onlineServers']"
                        icon="o-chart-bar"
                        variant="neon"
                    />

                    {{-- Offline Servers (use list-bullet as a neutral alternative) --}}
                    <x-stat-card
                        title="Offline Servers"
                        :value="$m['offlineServers']"
                        icon="o-list-bullet"
                        variant="mag"
                    />

                    {{-- (Optional) add another metric tile here if needed --}}
                </div>
            </x-section-card>

            {{-- ACTIVE CONNECTIONS (if a table/slot was provided) --}}
            @isset($connectionsTable)
                <x-section-card title="Active Connections">
                    {{ $connectionsTable }}
                </x-section-card>
            @endisset

            {{-- Helper table: latest VPN users --}}
            <x-section-card title="VPN Users">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-left table-dark">
                        <thead>
                            <tr class="text-[var(--aio-sub)] uppercase text-xs">
                                <th class="px-4 py-2 text-left">Username</th>
                                <th class="px-4 py-2 text-left">Password</th>
                                <th class="px-4 py-2 text-left">Servers Assigned</th>
                                <th class="px-4 py-2 text-left">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(VpnUser::with('vpnServers')->latest('id')->limit(10)->get() as $user)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-[var(--aio-ink)]">
                                        {{ $user->username }}
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($user->plain_password)
                                            <span class="aio-pill pill-cya text-xs">{{ $user->plain_password }}</span>
                                        @else
                                            <span class="text-[var(--aio-sub)] italic">N/A</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($user->vpnServers->isNotEmpty())
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($user->vpnServers as $server)
                                                    <span class="aio-pill pill-pup text-xs">
                                                        {{ $server->name }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-[var(--aio-sub)]">No servers assigned</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-[var(--aio-ink)]">
                                        {{ optional($user->created_at)->diffForHumans() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-section-card>

        </div>
    </div>
</x-app-layout>