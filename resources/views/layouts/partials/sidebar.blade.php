<nav class="px-2 pb-4 space-y-1 text-sm" aria-label="Sidebar">
    {{-- MAIN --}}
    <x-side-link href="{{ route('admin.dashboard') }}"
                 :active="request()->routeIs('admin.dashboard')"
                 icon="o-home">
        Dashboard
    </x-side-link>

    <x-side-link href="{{ route('admin.vpn-dashboard') }}"
                 :active="request()->routeIs('admin.vpn-dashboard')"
                 icon="o-chart-bar">
        VPN Monitor
    </x-side-link>

    {{-- MOVED: Servers just under VPN Monitor --}}
    <x-side-link href="{{ route('admin.servers.index') }}"
                 :active="request()->routeIs('admin.servers.*')"
                 icon="o-server">
        Servers
    </x-side-link>

    {{-- USERS --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Users</div>

        <x-side-link href="{{ route('admin.resellers.create') }}"
                     :active="request()->routeIs('admin.resellers.create')"
                     icon="o-plus">
            Add User
        </x-side-link>

        <x-side-link href="{{ route('admin.resellers.index') }}"
                     :active="request()->routeIs('admin.resellers.index')"
                     icon="o-user-group">
            Manage Users
        </x-side-link>
    </div>

    {{-- LINES --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Lines</div>

        <x-side-link href="{{ route('admin.vpn-users.create') }}"
                     :active="request()->routeIs('admin.vpn-users.create')"
                     icon="o-plus-circle">
            Add Line
        </x-side-link>

        <x-side-link href="{{ route('admin.vpn-users.trial') }}"
                     :active="request()->routeIs('admin.vpn-users.trial')"
                     icon="o-clock">
            Generate Trial
        </x-side-link>

        <x-side-link href="{{ route('admin.vpn-users.index') }}"
                     :active="request()->routeIs('admin.vpn-users.index')"
                     icon="o-list-bullet">
            Manage Lines
        </x-side-link>
    </div>

    {{-- SETTINGS --}}
    <x-side-link href="{{ route('admin.settings') }}"
                 :active="request()->routeIs('admin.settings')"
                 icon="o-cog-6-tooth">
        Settings
    </x-side-link>

    {{-- DIVIDER (the blue line area in your screenshot) --}}
    <div class="my-4 h-px bg-gray-200"></div>

    {{-- CREDITS --}}
    @auth
        @php
            $u = auth()->user();
            $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
            $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
            $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
        @endphp
        @if ($isAdmin || $isReseller)
            <x-side-link href="{{ $creditsUrl }}" icon="o-banknotes">
                Credits: {{ $u->credits }}
            </x-side-link>
        @endif
    @endauth
</nav>