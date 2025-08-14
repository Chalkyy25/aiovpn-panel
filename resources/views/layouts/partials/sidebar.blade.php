{{-- Vertical sidebar navigation --}}
<nav class="px-2 pb-4 space-y-1 text-sm" aria-label="Sidebar">

    {{-- MAIN --}}
    <x-nav-link href="{{ route('admin.dashboard') }}"
                :active="request()->routeIs('admin.dashboard')"
                icon="o-home">
        Dashboard
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-dashboard') }}"
                :active="request()->routeIs('admin.vpn-dashboard')"
                icon="o-chart-bar">
        VPN Monitor
    </x-nav-link>

    {{-- MOVED: Servers directly under VPN Monitor --}}
    <x-nav-link href="{{ route('admin.servers.index') }}"
                :active="request()->routeIs('admin.servers.*')"
                icon="o-server">
        Servers
    </x-nav-link>

    {{-- USERS --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Users</div>

        <x-nav-link href="{{ route('admin.resellers.create') }}"
                    :active="request()->routeIs('admin.resellers.create')"
                    icon="o-plus">
            Add User
        </x-nav-link>

        <x-nav-link href="{{ route('admin.resellers.index') }}"
                    :active="request()->routeIs('admin.resellers.index')"
                    icon="o-user-group">
            Manage Users
        </x-nav-link>
    </div>

    {{-- LINES --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Lines</div>

        <x-nav-link href="{{ route('admin.vpn-users.create') }}"
                    :active="request()->routeIs('admin.vpn-users.create')"
                    icon="o-plus-circle">
            Add Line
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.trial') }}"
                    :active="request()->routeIs('admin.vpn-users.trial')"
                    icon="o-clock">
            Generate Trial
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.index') }}"
                    :active="request()->routeIs('admin.vpn-users.index')"
                    icon="o-list-bullet">
            Manage Lines
        </x-nav-link>
    </div>

    {{-- SETTINGS --}}
    <x-nav-link href="{{ route('admin.settings') }}"
                :active="request()->routeIs('admin.settings')"
                icon="o-cog-6-tooth">
        Settings
    </x-nav-link>

    {{-- DIVIDER BEFORE CREDITS --}}
    <hr class="my-4 border-gray-200">

    {{-- CREDITS (Admin / Reseller only) --}}
    @auth
        @php
            $u = auth()->user();
            $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
            $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
            $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
        @endphp
        @if ($isAdmin || $isReseller)
            <x-nav-link href="{{ $creditsUrl }}" icon="o-banknotes">
                Credits: {{ $u->credits }}
            </x-nav-link>
        @endif
    @endauth
</nav>