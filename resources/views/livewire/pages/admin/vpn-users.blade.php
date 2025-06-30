<div class="p-4">
    <h2 class="text-xl font-semibold mb-4">VPN Test Users</h2>

    <div class="overflow-x-auto">
        <table class="min-w-full bg-white rounded shadow">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Username</th>
                    <th class="px-4 py-2 text-left">Server</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $user->username }}</td>
                    <td class="px-4 py-2">{{ $user->vpnServer->name ?? 'N/A' }}</td>
                    <td class="px-4 py-2 space-x-2">
                        <button class="bg-blue-500 text-white px-3 py-1 rounded text-sm">Download</button>
                        <button class="bg-red-500 text-white px-3 py-1 rounded text-sm">Delete</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
