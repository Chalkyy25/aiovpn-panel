@php
    // Expect these from controller; safe defaults if not set
    $metrics = $metrics ?? [
        'online_users'       => 0,
        'active_connections' => 0,
        'active_servers'     => 0,
        'avg_time'           => '0m',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">VPN Dashboard</h1>
                <p class="text-gray-500 dark:text-gray-400">Real‑time monitoring of VPN connections</p>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Last updated: {{ now()->format('H:i:s') }}
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Stats row (like the circled UI) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <x-stat-card title="Online Users"        :value="$metrics['online_users']"       icon="o-user-group"/>
                <x-stat-card title="Active Connections"  :value="$metrics['active_connections']" icon="o-rectangle-stack"/>
                <x-stat-card title="Active Servers"      :value="$metrics['active_servers']"     icon="o-server"/>
                <x-stat-card title="Avg. Connection Time":value="$metrics['avg_time']"           icon="o-clock" hint="rolling 24h"/>
            </div>

            {{-- Servers block (keep your own UI here) --}}
            <x-section-card title="Servers">
                {{-- Example: your existing server tabs/buttons/pills --}}
                {{ $serverTabs ?? '' }}
            </x-section-card>

            {{-- Active Connections (your table UI here, if you have it) --}}
            @isset($connectionsTable)
                <div class="mt-6">
                    <x-section-card title="Active Connections">
                        {{ $connectionsTable }}
                    </x-section-card>
                </div>
            @endisset

            {{-- Helper table (kept from your original) --}}
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
                            @foreach(($users ?? []) as $user)
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
                                        @if ($user->vpnServers?->isNotEmpty())
                                            <ul class="list-disc ml-4">
                                                @foreach ($user->vpnServers as $server)
                                                    <li>{{ $server->name }} ({{ $server->ip_address }})</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-gray-500">No servers assigned</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
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
    </div>
</x-app-layout>