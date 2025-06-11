<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Box -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    You're logged in as <strong>Admin</strong>!
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">👥 Total Users</h3>
                    <p class="text-2xl font-bold text-gray-800 mt-2">{{ $totalUsers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">✅ Active Users</h3>
                    <p class="text-2xl font-bold text-green-600 mt-2">{{ $activeUsers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">🧑‍💼 Resellers</h3>
                    <p class="text-2xl font-bold text-indigo-600 mt-2">{{ $totalResellers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">🙋 Clients</h3>
                    <p class="text-2xl font-bold text-blue-600 mt-2">{{ $totalClients }}</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
