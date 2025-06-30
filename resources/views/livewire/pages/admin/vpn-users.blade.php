<div class="p-6">
    <h1 class="text-2xl font-bold mb-4">VPN Users</h1>

    <table class="min-w-full bg-white rounded shadow">
        <thead>
            <tr>
                <th class="py-2 px-4 border-b">ID</th>
                <th class="py-2 px-4 border-b">Username</th>
                <th class="py-2 px-4 border-b">Password</th>
                <th class="py-2 px-4 border-b">Server</th>
                <th class="py-2 px-4 border-b">Server IP</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
            <tr>
                <td class="py-2 px-4 border-b">{{ $user->id }}</td>
                <td class="py-2 px-4 border-b">{{ $user->username }}</td>
                <td class="py-2 px-4 border-b">{{ $user->password }}</td>
                <td class="py-2 px-4 border-b">{{ $user->vpnServer->name ?? 'N/A' }}</td>
                <td class="py-2 px-4 border-b">{{ $user->vpnServer->ip_address ?? 'N/A' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
