<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold">My Lines</h2>
        <a href="{{ route('reseller.clients.create') }}"
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            + Create Line
        </a>
    </div>

    <div class="mb-3">
        <input type="text" wire:model.debounce.300ms="search"
               class="w-full md:w-1/3 border rounded px-3 py-2"
               placeholder="Search username...">
    </div>

    <div class="bg-white dark:bg-gray-900 rounded shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-2 text-left">Username</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Expires</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $u)
                    <tr class="border-t border-gray-200 dark:border-gray-800">
                        <td class="px-4 py-2 font-mono">{{ $u->username }}</td>
                        <td class="px-4 py-2">
                            @if($u->is_online)
                                <span class="text-green-600">Online</span>
                            @else
                                <span class="text-gray-500">Offline</span>
                            @endif
                            @if($u->online_since)
                                <div class="text-xs text-green-600">
                                    Online — {{ $u->online_since->diffForHumans() }}
                                </div>
                            @elseif($u->last_seen_at)
                                <div class="text-xs text-gray-500">
                                    Last seen {{ $u->last_seen_at->diffForHumans() }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            {{ $u->expires_at?->format('Y-m-d') ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{-- add download, disable, regenerate, etc --}}
                            <a href="{{ route('admin.vpn-users.edit', $u->id) }}" class="text-blue-600 hover:underline">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-gray-500" colspan="4">No lines yet.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="p-4">
            {{ $lines->links() }}
        </div>
    </div>
</div>