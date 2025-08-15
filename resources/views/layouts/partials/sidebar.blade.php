{{-- Vertical sidebar navigation --}}
<nav class="px-2 pb-4 space-y-1 text-sm text-[var(--aio-ink)]" aria-label="Sidebar">

    {{-- MAIN --}}
    <x-nav-link href="{{ route('admin.dashboard') }}"
                :active="request()->routeIs('admin.dashboard')"
                icon="o-home"
                class="aio-pill w-full justify-start">
        Dashboard
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-dashboard') }}"
                :active="request()->routeIs('admin.vpn-dashboard')"
                icon="o-chart-bar"
                class="aio-pill w-full justify-start">
        VPN Monitor
    </x-nav-link>

    {{-- MOVED: Servers directly under VPN Monitor --}}
    <x-nav-link href="{{ route('admin.servers.index') }}"
                :active="request()->routeIs('admin.servers.*')"
                icon="o-server"
                class="aio-pill w-full justify-start">
        Servers
    </x-nav-link>

    {{-- USERS --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
             x-show="!$root.sidebarCollapsed">Users</div>

        <x-nav-link href="{{ route('admin.resellers.create') }}"
                    :active="request()->routeIs('admin.resellers.create')"
                    icon="o-plus"
                    class="aio-pill w-full justify-start">
            Add User
        </x-nav-link>

        <x-nav-link href="{{ route('admin.resellers.index') }}"
                    :active="request()->routeIs('admin.resellers.index')"
                    icon="o-user-group"
                    class="aio-pill w-full justify-start">
            Manage Users
        </x-nav-link>
    </div>

    {{-- LINES --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
             x-show="!$root.sidebarCollapsed">Lines</div>

        <x-nav-link href="{{ route('admin.vpn-users.create') }}"
                    :active="request()->routeIs('admin.vpn-users.create')"
                    icon="o-plus-circle"
                    class="aio-pill w-full justify-start">
            Add Line
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.trial') }}"
                    :active="request()->routeIs('admin.vpn-users.trial')"
                    icon="o-clock"
                    class="aio-pill w-full justify-start">
            Generate Trial
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.index') }}"
                    :active="request()->routeIs('admin.vpn-users.index')"
                    icon="o-list-bullet"
                    class="aio-pill w-full justify-start">
            Manage Lines
        </x-nav-link>
    </div>

    {{-- SETTINGS --}}
    <x-nav-link href="{{ route('admin.settings') }}"
                :active="request()->routeIs('admin.settings')"
                icon="o-cog-6-tooth"
                class="aio-pill w-full justify-start">
        Settings
    </x-nav-link>

    {{-- DIVIDER BEFORE CREDITS --}}
    <hr class="my-4 aio-divider">

    {{-- CREDITS (Admin / Reseller only) --}}
    @auth
        @php
            $u = auth()->user();
            $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
            $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
            $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
        @endphp
        @if ($isAdmin || $isReseller)
            <x-nav-link href="{{ $creditsUrl }}" icon="o-banknotes"
                        class="aio-pill w-full justify-start">
                Credits: {{ $u->credits }}
            </x-nav-link>
        @endif
    @endauth
</nav>