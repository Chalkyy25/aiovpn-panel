<nav class="bg-white border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
                <a href="{{ route('admin.dashboard') }}">
                    <img class="h-8 w-auto" src="{{ asset('logo.png') }}" alt="Logo">
                </a>
            </div>

            <!-- Desktop Menu -->
            <div class="hidden md:flex md:items-center md:space-x-8">
                <a href="{{ route('admin.dashboard') }}" class="text-gray-700 hover:text-indigo-600 font-medium">
                    Dashboard
                </a>
                <a href="{{ route('admin.vpn-user-list') }}" class="text-gray-700 hover:text-indigo-600 font-medium">
                    VPN Users
                </a>
                <a href="{{ route('admin.servers.index') }}" class="text-gray-700 hover:text-indigo-600 font-medium">
                    VPN Servers
                </a>
                <a href="{{ route('admin.settings') }}" class="text-gray-700 hover:text-indigo-600 font-medium">
                    Settings
                </a>
            </div>

            <!-- Mobile Menu Button -->
            <div class="flex items-center md:hidden">
                <button @click="open = !open" type="button" class="text-gray-500 hover:text-gray-700 focus:outline-none focus:text-gray-700">
                    <!-- Menu Icon -->
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div x-show="open" class="md:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <a href="{{ route('admin.dashboard') }}" class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-100">
                Dashboard
            </a>
            <a href="{{ route('admin.vpn-user-list') }}" class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-100">
                VPN Users
            </a>
            <a href="{{ route('admin.servers.index') }}" class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-100">
                VPN Servers
            </a>
            <a href="{{ route('admin.settings') }}" class="block pl-3 pr-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-100">
                Settings
            </a>
        </div>
    </div>
</nav>
