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
    <!-- Stat cards -->
</div>

            <!-- ✅ User ID Helper Table -->
            <div class="bg-white shadow rounded p-4 mt-6">
                <h3 class="text-lg font-semibold mb-2">🆔 User ID Helper</h3>
                <table class="min-w-full text-sm text-left">
                    <thead class="border-b">
                        <tr>
                    <th class="px-4 py-2 text-left">Username</th>
                    <th class="px-4 py-2 text-left">Password</th>
                    <th class="px-4 py-2 text-left">Servers Assigned</th>
                    <th class="px-4 py-2 text-left">Created</th>
                </tr>
                    </thead>
                    <tbody>
                        @foreach(\App\Models\VpnUser::all() as $user)
                            <tr class="border-b">
                                <td class="px-2 py-1">{{ $user->username }}</td>
                                 <td class="px-4 py-2">
                            @if ($user->plain_password)
                                {{ $user->plain_password }}
                            @else
                                <span class="text-gray-400 italic">N/A</span>
                            @endif
                        </td>
                                <td class="px-2 py-1">{{ $user->vpnServers }}</td>
                                <td class="px-2 py-1">{{ $user->created }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>
