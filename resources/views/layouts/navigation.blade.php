{{-- resources/views/layouts/navigation.blade.php --}}
<nav class="px-2 pb-4 space-y-1 text-sm" aria-label="Sidebar">
    {{-- Top brand (desktop sidebar header already shows the logo; keep this minimal here) --}}
    {{-- You can remove this block if you prefer only the header logo --}}
    <div class="px-3 py-1.5 font-semibold" x-show="!$root.sidebarCollapsed">
        AIO VPN
    </div>

    {{-- Main --}}
    <x-nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.dashboard')"
                class="group flex items-center gap-3 px-3 py-2 rounded-md">
        <x-icon name="o-home" class="w-5 h-5 shrink-0"/>
        <span class="truncate" x-show="!$root.sidebarCollapsed">Dashboard</span>
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-dashboard') }}" :active="request()->routeIs('admin.vpn-dashboard')"
                class="group flex items-center gap-3 px-3 py-2 rounded-md">
        <x-icon name="o-chart-bar" class="w-5 h-5 shrink-0"/>
        <span class="truncate" x-show="!$root.sidebarCollapsed">VPN Monitor</span>
    </x-nav-link>

    {{-- Users group --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Users</div>

        <x-nav-link href="{{ route('admin.resellers.create') }}"
                    :active="request()->routeIs('admin.resellers.create')"
                    class="group flex items-center gap-3 px-3 py-2 rounded-md">
            <x-icon name="o-plus" class="w-5 h-5 shrink-0"/>
            <span class="truncate" x-show="!$root.sidebarCollapsed">Add User</span>
        </x-nav-link>

        <x-nav-link href="{{ route('admin.resellers.index') }}"
                    :active="request()->routeIs('admin.resellers.index')"
                    class="group flex items-center gap-3 px-3 py-2 rounded-md">
            <x-icon name="o-user-group" class="w-5 h-5 shrink-0"/>
            <span class="truncate" x-show="!$root.sidebarCollapsed">Manage Users</span>
        </x-nav-link>
    </div>

    {{-- Lines group --}}
    <div class="mt-3">
        <div class="px-3 text-[11px] uppercase tracking-wide text-gray-500"
             x-show="!$root.sidebarCollapsed">Lines</div>

        <x-nav-link href="{{ route('admin.vpn-users.create') }}"
                    :active="request()->routeIs('admin.vpn-users.create')"
                    class="group flex items-center gap-3 px-3 py-2 rounded-md">
            <x-icon name="o-plus-circle" class="w-5 h-5 shrink-0"/>
            <span class="truncate" x-show="!$root.sidebarCollapsed">Add Line</span>
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.trial') }}"
                    :active="request()->routeIs('admin.vpn-users.trial')"
                    class="group flex items-center gap-3 px-3 py-2 rounded-md">
            <x-icon name="o-clock" class="w-5 h-5 shrink-0"/>
            <span class="truncate" x-show="!$root.sidebarCollapsed">Generate Trial Line</span>
        </x-nav-link>

        <x-nav-link href="{{ route('admin.vpn-users.index') }}"
                    :active="request()->routeIs('admin.vpn-users.index')"
                    class="group flex items-center gap-3 px-3 py-2 rounded-md">
            <x-icon name="o-list-bullet" class="w-5 h-5 shrink-0"/>
            <span class="truncate" x-show="!$root.sidebarCollapsed">Manage Lines</span>
        </x-nav-link>
    </div>

    {{-- Servers & Settings --}}
    <x-nav-link href="{{ route('admin.servers.index') }}" :active="request()->routeIs('admin.servers.*')"
                class="group flex items-center gap-3 px-3 py-2 rounded-md">
        <x-icon name="o-server" class="w-5 h-5 shrink-0"/>
        <span class="truncate" x-show="!$root.sidebarCollapsed">Servers</span>
    </x-nav-link>

    <x-nav-link href="{{ route('admin.settings') }}" :active="request()->routeIs('admin.settings')"
                class="group flex items-center gap-3 px-3 py-2 rounded-md">
        <x-icon name="o-cog-6-tooth" class="w-5 h-5 shrink-0"/>
        <span class="truncate" x-show="!$root.sidebarCollapsed">Settings</span>
    </x-nav-link>

    {{-- (Optional) Credits shortcut in sidebar as a link --}}
    @auth
        @php
            $u = auth()->user();
            $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
            $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
            $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
        @endphp
        @if ($isAdmin || $isReseller)
            <x-nav-link href="{{ $creditsUrl }}" class="group flex items-center gap-3 px-3 py-2 rounded-md mt-3">
                <x-icon name="o-banknotes" class="w-5 h-5 shrink-0"/>
                <span class="truncate" x-show="!$root.sidebarCollapsed">Credits: {{ $u->credits }}</span>
            </x-nav-link>
        @endif
    @endauth
</nav>