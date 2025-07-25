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
                    <h3 class="text-sm font-semibold text-gray-600">👥 Total VPN Users</h3>
                    <p class="text-2xl font-bold text-gray-800 mt-2">{{ $totalUsers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">✅ Active VPN Users</h3>
                    <p class="text-2xl font-bold text-green-600 mt-2">{{ $activeUsers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">🌐 VPN Servers</h3>
                    <p class="text-2xl font-bold text-indigo-600 mt-2">{{ $totalResellers }}</p>
                </div>

                <div class="bg-white shadow rounded p-4">
                    <h3 class="text-sm font-semibold text-gray-600">🔗 Active Connections</h3>
                    <p class="text-2xl font-bold text-blue-600 mt-2">{{ $totalClients }}</p>
                </div>
            </div>

            <!-- ✅ User ID Helper Table -->
            <div class="bg-white shadow rounded p-4 mt-6">
                <h3 class="text-lg font-semibold mb-2">🆔 User ID Helper</h3>
                <table class="min-w-full text-sm text-left">
                    <thead class="border-b">
                        <tr>
                            <th class="px-2 py-1">ID</th>
                            <th class="px-2 py-1">Name</th>
                            <th class="px-2 py-1">Role</th>
                            <th class="px-2 py-1">Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(\App\Models\User::all() as $user)
                            <tr class="border-b">
                                <td class="px-2 py-1">{{ $user->id }}</td>
                                <td class="px-2 py-1">{{ $user->name }}</td>
                                <td class="px-2 py-1">{{ $user->role }}</td>
                                <td class="px-2 py-1">{{ $user->email }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>
