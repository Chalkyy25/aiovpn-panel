<nav
    class="xui-nav"
    x-data="{
        open: false,
        usersOpen: false, usersTimeout: null,
        linesOpen: false, linesTimeout: null
    }"
>
    <div class="xui-container">
        <div class="xui-nav-inner">

            {{-- Logo --}}
            <div class="xui-nav-logo">
                <a href="/">
                    <x-application-logo class="xui-logo" />
                </a>
            </div>

            {{-- Desktop menu --}}
            <div class="xui-nav-menu">
                <x-nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.dashboard')">
                    Dashboard
                </x-nav-link>

                <x-nav-link href="{{ route('admin.vpn-dashboard') }}" :active="request()->routeIs('admin.vpn-dashboard')">
                    VPN Monitor
                </x-nav-link>

                {{-- Users dropdown --}}
                <div
                    class="xui-dropdown"
                    @mouseenter="clearTimeout(usersTimeout); usersOpen = true"
                    @mouseleave="usersTimeout = setTimeout(() => usersOpen = false, 300)"
                >
                    <x-nav-link href="#" :active="false">Users</x-nav-link>
                    <div
                        x-show="usersOpen" x-cloak x-transition
                        class="xui-dropdown-menu"
                        @mouseenter="clearTimeout(usersTimeout)"
                        @mouseleave="usersTimeout = setTimeout(() => usersOpen = false, 300)"
                    >
                        <x-nav-link href="{{ route('admin.resellers.create') }}">Add User</x-nav-link>
                        <x-nav-link href="{{ route('admin.resellers.index') }}">Manage Users</x-nav-link>
                    </div>
                </div>

                {{-- Lines dropdown --}}
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

                {{-- Other items --}}
                <x-nav-link href="{{ route('admin.servers.index') }}" :active="request()->routeIs('admin.servers.*')">
                    Servers
                </x-nav-link>
                <x-nav-link href="{{ route('admin.settings') }}" :active="request()->routeIs('admin.settings')">
                    Settings
                </x-nav-link>
            </div>

            {{-- Right side: Credits pill + mobile toggle --}}
            <div class="flex items-center gap-3">

                @auth
                    @php
                        $u = auth()->user();
                        $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
                        $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
                        $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
                    @endphp

                    @if ($isAdmin || $isReseller)
                        {{-- Persistent credits pill with icon (visible on all breakpoints) --}}
                        <a href="{{ $creditsUrl }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold
                                  bg-gray-100 text-gray-900 hover:bg-gray-200
                                  dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700">
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none">
                              <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
                              <text x="16" y="20" text-anchor="middle" font-size="16" font-weight="bold" fill="currentColor">$</text>
                            </svg>
                          <span>{{ $u->credits }}</span>
                        </a>
                    @endif
                @endauth

                {{-- Mobile toggle --}}
                <div class="xui-nav-mobile-toggle">
                    <button type="button" @click="open = !open" class="xui-mobile-button">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex"
                                  stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 6h16M4 12h16M4 18h16"/>
                            <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden"
                                  stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </div>

    {{-- Mobile drawer --}}
    <div class="xui-mobile-menu" x-show="open" x-cloak x-transition>
        <div class="xui-mobile-menu-inner">
            <x-nav-link href="{{ route('admin.dashboard') }}">🏠 Dashboard</x-nav-link>
            <x-nav-link href="{{ route('admin.vpn-dashboard') }}">📊 VPN Monitor</x-nav-link>

            {{-- Credits quick view on mobile (same link as pill) --}}
            @auth
                @if ($isAdmin || $isReseller)
                    <div class="xui-mobile-section">
                        <div class="xui-mobile-section-title">Credits</div>
                        <a href="{{ $creditsUrl }}" class="flex items-center justify-between px-3 py-2 rounded bg-gray-100 dark:bg-gray-800">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v1.1a4 4 0 012.6 1.4l-1.4 1.4a2.1 2.1 0 00-1.2-.7V11h1a3 3 0 010 6h-1v1h-2v-1.1A4 4 0 018.4 15l1.4-1.4a2.1 2.1 0 001.2.7V13h-1a3 3 0 010-6h1V6h2z"/>
                                </svg>
                                <span class="font-medium">Balance</span>
                            </div>
                            <span class="font-semibold">{{ $u->credits }} CR</span>
                        </a>
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