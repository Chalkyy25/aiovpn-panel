<nav
    class="xui-nav"
    x-data="{
        open: false,
        usersOpen: false,
        usersTimeout: null,
        linesOpen: false,
        linesTimeout: null
    }"
>
    <div class="xui-container">
        <div class="xui-nav-inner">

            <!-- Logo -->
            <div class="xui-nav-logo">
                <a href="/">
                    <x-application-logo class="xui-logo" />
                </a>
            </div>

            <!-- Desktop menu -->
            <div class="xui-nav-menu">
                <x-nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.dashboard')">
                    Dashboard
                </x-nav-link>

                <!-- Users Dropdown -->
                <div
                    class="xui-dropdown"
                    @mouseenter="clearTimeout(usersTimeout); usersOpen = true"
                    @mouseleave="usersTimeout = setTimeout(() => usersOpen = false, 300)"
                >
                    <x-nav-link href="#" :active="false">Users</x-nav-link>
                    <div
                        x-show="usersOpen"
                        x-cloak
                        x-transition
                        class="xui-dropdown-menu"
                        @mouseenter="clearTimeout(usersTimeout)"
                        @mouseleave="usersTimeout = setTimeout(() => usersOpen = false, 300)"
                    >
                        <x-nav-link href="{{ route('admin.resellers.create') }}">Add User</x-nav-link>
                        <x-nav-link href="{{ route('admin.resellers.index') }}">Manage Users</x-nav-link>
                    </div>
                </div>

                <!-- Lines Dropdown -->
                <div
                    class="xui-dropdown"
                    @mouseenter="clearTimeout(linesTimeout); linesOpen = true"
                    @mouseleave="linesTimeout = setTimeout(() => linesOpen = false, 300)"
                >
                    <x-nav-link href="#" :active="false">Lines</x-nav-link>
                    <div
                        x-show="linesOpen"
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        class="xui-dropdown-menu"
                        @mouseenter="clearTimeout(linesTimeout)"
                        @mouseleave="linesTimeout = setTimeout(() => linesOpen = false, 300)"
                    >
                        <x-nav-link href="{{ route('admin.vpn-users.create') }}">Add Line</x-nav-link>
                        <x-nav-link href="#">Generate Trial Line</x-nav-link>
                        <x-nav-link href="{{ route('admin.vpn-users.index') }}">Manage Lines</x-nav-link>
                    </div>
                </div>

                <!-- Other nav items -->
                <x-nav-link href="{{ route('admin.servers.index') }}" :active="request()->routeIs('admin.servers.*')">
                    Servers
                </x-nav-link>
                <x-nav-link href="{{ route('admin.settings') }}" :active="request()->routeIs('admin.settings')">
                    Settings
                </x-nav-link>
            </div>

            <!-- Mobile toggle -->
            <div class="xui-nav-mobile-toggle">
                <button type="button" @click="open = !open"
                        class="xui-mobile-button"
                >
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex"
                              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden"
                              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile dropdown -->
    <div class="xui-mobile-menu" x-show="open" x-cloak x-transition>
        <div class="xui-mobile-menu-inner">
            <x-nav-link href="{{ route('admin.dashboard') }}">🏠 Dashboard</x-nav-link>

            <div class="xui-mobile-section">
                <div class="xui-mobile-section-title">Users</div>
                <x-nav-link href="{{ route('admin.resellers.create') }}">➕ Add User</x-nav-link>
                <x-nav-link href="{{ route('admin.resellers.index') }}">👥 Manage Users</x-nav-link>
            </div>

            <div class="xui-mobile-section">
                <div class="xui-mobile-section-title">Lines</div>
                <x-nav-link href="{{ route('admin.vpn-users.create') }}">➕ Add Line</x-nav-link>
                <x-nav-link href="#">🎁 Trial Line</x-nav-link>
                <x-nav-link href="{{ route('admin.vpn-users.index') }}">📋 Manage Lines</x-nav-link>
            </div>

            <x-nav-link href="{{ route('admin.servers.index') }}">🌐 Servers</x-nav-link>
            <x-nav-link href="{{ route('admin.settings') }}">⚙️ Settings</x-nav-link>
        </div>
    </div>
</nav>
