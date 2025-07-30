<div class="max-w-7xl mx-auto p-4">
    <h2 class="text-xl font-semibold mb-4">VPN Users for Server: {{ $server->name }}</h2>

    @if (session()->has('success'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300 mb-4">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full bg-white shadow rounded">
            <thead class="bg-gray-100">
            <tr>
                <th class="px-4 py-2 text-left">Username</th>
                <th class="px-4 py-2 text-left">Created</th>
                <th class="px-4 py-2 text-left">Actions</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($users as $user)
                <tr class="border-b">
                    <td class="px-4 py-2">{{ $user->username }}</td>
                    <td class="px-4 py-2">{{ $user->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2 space-x-2">
                        <a href="{{ route('admin.clients.config.download', $user->id) }}" class="bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700">
                            WG Config
                        </a>
                        <form action="{{ route('admin.servers.users.sync', [$server->id]) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">Sync</button>
                        </form>
                        <form action="{{ route('admin.servers.users.store', [$server->id]) }}" method="POST" class="inline" onsubmit="return confirm('Confirm delete?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
