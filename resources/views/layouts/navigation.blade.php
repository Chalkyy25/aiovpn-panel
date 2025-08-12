<nav
    class="xui-nav"
    x-data="{
    open: false,
    usersOpen: false,
    usersTimeout: null,
    linesOpen: false,
    linesTimeout: null,
    creditsOpen: false,
    creditsTimeout: null
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

                <x-nav-link href="{{ route('admin.vpn-dashboard') }}" :active="request()->routeIs('admin.vpn-dashboard')">
                    VPN Monitor
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
            
            @auth
    @if (auth()->user()->isAdmin() || auth()->user()->isReseller())
        <div
            class="relative ml-4 hidden md:flex items-center"
            @mouseenter="clearTimeout(creditsTimeout); creditsOpen = true"
            @mouseleave="creditsTimeout = setTimeout(() => creditsOpen = false, 250)"
        >
            <button type="button"
                    class="flex items-center gap-1 px-3 py-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                    @click="creditsOpen = !creditsOpen">
                {{-- coin icon --}}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v1.1a3.9 3.9 0 012.6 1.4l-1.4 1.4A2.1 2.1 0 0013 9.7V11h1a3 3 0 010 6h-1v1h-2v-1.1A3.9 3.9 0 018.4 15l1.4-1.4a2.1 2.1 0 001.2.7V13h-1a3 3 0 010-6h1V6h2z"/>
                </svg>
                <span class="font-semibold">{{ auth()->user()->credits }}</span>
                <svg class="h-4 w-4 opacity-70" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M5.23 7.21L10 12l4.77-4.79-1.42-1.41L10 9.17 6.65 5.8 5.23 7.2z"/>
                </svg>
            </button>

            <div x-show="creditsOpen" x-cloak x-transition
                 class="absolute right-0 top-full mt-2 w-64 rounded-md bg-white dark:bg-gray-900 shadow-lg ring-1 ring-black/5 z-50">
                <div class="px-4 py-3 text-sm">
                    Balance:
                    <span class="font-bold">{{ auth()->user()->credits }}</span> credits
                </div>

                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.credits') }}"
                       class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800">
                        Manage reseller credits
                    </a>
                @endif

                @if (auth()->user()->isReseller())
                    <a href="{{ route('reseller.credits') }}"
                       class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-800">
                        Credits & history
                    </a>
                @endif
            </div>
        </div>
    @endif
@endauth

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
            <x-nav-link href="{{ route('admin.vpn-dashboard') }}">📊 VPN Monitor</x-nav-link>
            
            @auth
    @if (auth()->user()->isAdmin() || auth()->user()->isReseller())
        <div class="xui-mobile-section">
            <div class="xui-mobile-section-title">Credits</div>
            <div class="flex items-center justify-between px-3 py-2 rounded bg-gray-100 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v1.1a3.9 3.9 0 012.6 1.4l-1.4 1.4A2.1 2.1 0 0013 9.7V11h1a3 3 0 010 6h-1v1h-2v-1.1A3.9 3.9 0 018.4 15l1.4-1.4a2.1 2.1 0 001.2.7V13h-1a3 3 0 010-6h1V6h2z"/>
                    </svg>
                    <span class="font-medium">Balance</span>
                </div>
                <span class="font-semibold">{{ auth()->user()->credits }}</span>
            </div>

            @if (auth()->user()->isAdmin())
                <x-nav-link href="{{ route('admin.credits') }}">🔧 Manage reseller credits</x-nav-link>
            @endif
            @if (auth()->user()->isReseller())
                <x-nav-link href="{{ route('reseller.credits') }}">🧾 Credits & history</x-nav-link>
            @endif
        </div>
    @endif
@endauth

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
