<aside class="w-64 bg-white border-r">
    <div class="p-4">
        <h2 class="text-lg font-semibold mb-4">AIO VPN</h2>

        {{-- Main Links --}}
        <x-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" icon="o-home">
            Dashboard
        </x-nav-link>

        <x-nav-link href="{{ route('vpn.monitor') }}" :active="request()->routeIs('vpn.monitor')" icon="o-chart-bar">
            VPN Monitor
        </x-nav-link>

        {{-- Servers moved here --}}
        <x-nav-link href="{{ route('servers.index') }}" :active="request()->routeIs('servers.*')" icon="o-server">
            Servers
        </x-nav-link>

        {{-- Users --}}
        <div class="mt-6 mb-2 px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Users
        </div>
        <x-nav-link href="{{ route('users.create') }}" :active="request()->routeIs('users.create')" icon="o-plus">
            Add User
        </x-nav-link>
        <x-nav-link href="{{ route('users.index') }}" :active="request()->routeIs('users.index')" icon="o-users">
            Manage Users
        </x-nav-link>

        {{-- Lines --}}
        <div class="mt-6 mb-2 px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Lines
        </div>
        <x-nav-link href="{{ route('lines.create') }}" :active="request()->routeIs('lines.create')" icon="o-plus">
            Add Line
        </x-nav-link>
        <x-nav-link href="{{ route('lines.trial') }}" :active="request()->routeIs('lines.trial')" icon="o-clock">
            Generate Trial
        </x-nav-link>
        <x-nav-link href="{{ route('lines.index') }}" :active="request()->routeIs('lines.index')" icon="o-list-bullet">
            Manage Lines
        </x-nav-link>

        {{-- Settings --}}
        <x-nav-link href="{{ route('settings') }}" :active="request()->routeIs('settings')" icon="o-cog">
            Settings
        </x-nav-link>

        {{-- Divider before Credits --}}
        <hr class="my-4 border-gray-200">

        {{-- Credits --}}
        <x-nav-link href="{{ route('credits') }}" :active="request()->routeIs('credits')" icon="o-banknotes">
            Credits: {{ auth()->user()->credits }}
        </x-nav-link>
    </div>
</aside>