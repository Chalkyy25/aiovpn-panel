<nav class="bg-white border-b border-gray-200" x-data="{ open: false }">
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
                <x-nav-link href="{{ route('admin.users.create') }}" :active="request()->routeIs('admin.users.create')">Create VPN User</x-nav-link>
                <x-nav-link href="{{ route('admin.vpn-users.index') }}" :active="request()->routeIs('admin.vpn-users.index')">VPN Users</x-nav-link>
                <x-nav-link href="{{ route('admin.servers.index') }}" :active="request()->routeIs('admin.servers.index')">VPN Servers</x-nav-link>
                <x-nav-link href="{{ route('admin.settings') }}" :active="request()->routeIs('admin.settings')">Settings</x-nav-link>
            </div>

            <!-- Mobile menu button -->
            <div class="sm:hidden flex items-center">
                <button @click="open = !open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16"></path>
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

        </div>
    </div>

    <!-- Mobile vertical menu -->
    <div class="sm:hidden" x-show="open">
        <div class="pt-4 pb-4 bg-gray-50 rounded shadow space-y-1">
            @foreach ([
            ['route' => 'admin.dashboard', 'label' => '🏠 Dashboard'],
            ['route' => 'admin.users.create', 'label' => '➕ Create VPN User'],
            ['route' => 'admin.vpn-users.index', 'label' => '🔑 VPN Users'],
            ['route' => 'admin.servers.index', 'label' => '🌐 VPN Servers'],
            ['route' => 'admin.settings', 'label' => '⚙️ Settings'],
            ] as $item)
            <a href="{{ route($item['route']) }}"
               class="block px-4 py-2 text-gray-700 hover:bg-gray-200 rounded
                          {{ request()->routeIs($item['route']) ? 'border-l-4 border-blue-500 bg-blue-50' : '' }}">
                {{ $item['label'] }}
            </a>
            @endforeach
        </div>
    </div>
</nav>
