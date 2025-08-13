<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold">Resellers</h2>

        <div class="flex items-center gap-3">
            <input
                type="text"
                wire:model.debounce.300ms="search"
                placeholder="Search name or email…"
                class="rounded-md border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-600">
                <th class="px-4 py-3">
                    <button wire:click="sort('name')" class="inline-flex items-center gap-1">
                        Name / Email
                        @if($sortBy==='name')
                            <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                        @endif
                    </button>
                </th>
                <th class="px-4 py-3">
                    <button wire:click="sort('credits')" class="inline-flex items-center gap-1">
                        Credits
                        @if($sortBy==='credits')
                            <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                        @endif
                    </button>
                </th>
                <th class="px-4 py-3">
                    <button wire:click="sort('lines_count')" class="inline-flex items-center gap-1">
                        # of Lines
                        @if($sortBy==='lines_count')
                            <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                        @endif
                    </button>
                </th>
                <th class="px-4 py-3">
                    <button wire:click="sort('last_login_at')" class="inline-flex items-center gap-1">
                        Last Login
                        @if($sortBy==='last_login_at')
                            <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                        @endif
                    </button>
                </th>
                <th class="px-4 py-3">
                    <button wire:click="sort('is_active')" class="inline-flex items-center gap-1">
                        Status
                        @if($sortBy==='is_active')
                            <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                        @endif
                    </button>
                </th>
                <th class="px-4 py-3 text-right">
                    Actions
                </th>
            </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
            @forelse($resellers as $r)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900">{{ $r->name ?? '—' }}</div>
                        <div class="text-sm text-gray-500">{{ $r->email }}</div>
                        <div class="text-xs text-gray-400">ID: {{ $r->id }}</div>
                    </td>

                    <td class="px-4 py-3">
                        <span class="inline-flex min-w-[2.5rem] items-center justify-center rounded-md bg-blue-50 px-2 py-1 text-sm font-semibold text-blue-700">
                            {{ (int) $r->credits }}
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="inline-flex min-w-[2.5rem] items-center justify-center rounded-md bg-indigo-50 px-2 py-1 text-sm font-semibold text-indigo-700">
                            {{ (int) ($r->lines_count ?? 0) }}
                        </span>
                    </td>

                    <td class="px-4 py-3 text-sm text-gray-700">
                        @if(!empty($r->last_login_at))
                            {{ \Carbon\Carbon::parse($r->last_login_at)->diffForHumans() }}
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        @if($r->is_active)
                            <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
                                Active
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-200 px-3 py-1 text-sm font-medium text-gray-700">
                                Disabled
                            </span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.resellers.index') }}?impersonate={{ $r->id }}"
                               class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 hover:bg-gray-50">
                                View
                            </a>
                            <a href="{{ route('admin.credits') }}?user={{ $r->id }}"
                               class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                                Credits
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                        No resellers found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $resellers->links() }}
    </div>
</div>