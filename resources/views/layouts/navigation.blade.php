<nav class="bg-white border-b border-gray-200" x-data="{ open: false, usersOpen: false, linesOpen: false }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">

            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
                <a href="/">
                    <x-application-logo class="block h-10 w-auto fill-current text-gray-600" />
                </a>
            </div>

            <!-- Desktop menu -->
            <div class="hidden sm:flex sm:items-center sm:space-x-8">
                <x-nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.dashboard')">Dashboard</x-nav-link>

                <!-- Users Section -->
                <div @mouseenter="usersOpen = true" @mouseleave="usersOpen = false" class="relative">
                    <x-nav-link href="#" :active="false">Users</x-nav-link>
                    <div
                        x-show="usersOpen"
                        x-cloak
                        x-transition
                        class="absolute mt-2 bg-white shadow rounded py-1 w-48 z-50"
                    >
                        <x-nav-link href="{{ route('admin.resellers.create') }}">Add User</x-nav-link>
                        <x-nav-link href="{{ route('admin.resellers.index') }}">Manage Users</x-nav-link>
                    </div>
                </div>

                <!-- Lines Section -->
                <div @mouseenter="linesOpen = true" @mouseleave="linesOpen = false" class="relative">
                    <x-nav-link href="#" :active="false">Lines</x-nav-link>
                    <div
                        x-show="linesOpen"
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        class="absolute mt-2 bg-white shadow rounded py-1 w-48 z-50"
                    >
                        <x-nav-link href="{{ route('admin.vpn-users.create') }}">Add Line</x-nav-link>
                        <x-nav-link href="#">Generate Trial Line</x-nav-link>
                        <x-nav-link href="{{ route('admin.vpn-users.index') }}">Manage Lines</x-nav-link>
                    </div>
                </div>

                <x-nav-link href="{{ route('admin.servers.index') }}" :active="request()->routeIs('admin.servers.*')">Servers</x-nav-link>
                <x-nav-link href="{{ route('admin.settings') }}" :active="request()->routeIs('admin.settings')">Settings</x-nav-link>
            </div>

            <!-- Mobile menu toggle -->
            <div class="sm:hidden flex items-center">
                <button type="button" x-on:click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile dropdown menu -->
    <div class="sm:hidden" x-show="open" x-cloak x-transition style="display: none;">
        <div class="bg-gray-50 py-4 px-4 space-y-2">
            <x-nav-link href="{{ route('admin.dashboard') }}">🏠 Dashboard</x-nav-link>

            <div>
                <div class="text-sm font-semibold text-gray-600 mt-2">Users</div>
                <x-nav-link href="{{ route('admin.resellers.create') }}">➕ Add User</x-nav-link>
                <x-nav-link href="{{ route('admin.resellers.index') }}">👥 Manage Users</x-nav-link>
            </div>

            <div>
                <div class="text-sm font-semibold text-gray-600 mt-2">Lines</div>
                <x-nav-link href="{{ route('admin.vpn-users.create') }}">➕ Add Line</x-nav-link>
                <x-nav-link href="#">🎁 Trial Line</x-nav-link>
                <x-nav-link href="{{ route('admin.vpn-users.index') }}">📋 Manage Lines</x-nav-link>
            </div>

            <x-nav-link href="{{ route('admin.servers.index') }}">🌐 Servers</x-nav-link>
            <x-nav-link href="{{ route('admin.settings') }}">⚙️ Settings</x-nav-link>
        </div>
    </div>
</nav>
