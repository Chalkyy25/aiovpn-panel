<div class="p-6 bg-white dark:bg-gray-900 shadow rounded">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Resellers</h2>

        <div class="w-64">
            <input
                type="text"
                wire:model.debounce.400ms="search"
                placeholder="Search name or email…"
                class="w-full bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-blue-500"
            />
        </div>
    </div>

    @if ($resellers->count())
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-3 pr-4">
                            <button wire:click="sort('name')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                Name
                                @if($sortBy==='name') <x-sort-icon :dir="$sortDir" /> @endif
                            </button>
                        </th>
                        <th class="py-3 pr-4">
                            <button wire:click="sort('email')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                Email
                                @if($sortBy==='email') <x-sort-icon :dir="$sortDir" /> @endif
                            </button>
                        </th>

                        <th class="py-3 pr-4">
                            <button wire:click="sort('credits')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                Credits
                                @if($sortBy==='credits') <x-sort-icon :dir="$sortDir" /> @endif
                            </button>
                        </th>

                        <th class="py-3 pr-4">
                            <button wire:click="sort('lines_count')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                # of Lines
                                @if($sortBy==='lines_count') <x-sort-icon :dir="$sortDir" /> @endif
                            </button>
                        </th>

                        <th class="py-3 pr-4">
                            <button wire:click="sort('last_login_at')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                Last Login
                                @if($sortBy==='last_login_at') <x-sort-icon :dir="$sortDir" /> @endif
                            </button>
                        </th>

                        <th class="py-3">Status</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($resellers as $r)
                        <tr class="text-gray-900 dark:text-gray-100">
                            <td class="py-3 pr-4">
                                <div class="font-medium">{{ $r->name }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $r->id }}</div>
                            </td>

                            <td class="py-3 pr-4">{{ $r->email }}</td>

                            <td class="py-3 pr-4">
                                <span class="inline-block px-2.5 py-1 rounded bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200 font-semibold">
                                    {{ (int)$r->credits }}
                                </span>
                            </td>

                            <td class="py-3 pr-4">
                                <span class="inline-block px-2.5 py-1 rounded bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200 font-semibold">
                                    {{ (int)($r->lines_count ?? 0) }}
                                </span>
                            </td>

                            <td class="py-3 pr-4">
                                @if($r->last_login_at)
                                    <span title="{{ $r->last_login_at }}">
                                        {{ \Carbon\Carbon::parse($r->last_login_at)->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            <td class="py-3">
                                @if($r->is_active ?? true)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $resellers->links() }}
        </div>
    @else
        <div class="p-6 text-gray-600 dark:text-gray-300">No resellers found.</div>
    @endif
</div>

{{-- tiny helper for sort chevrons --}}
@once
    @push('components')
        <x-slot name="sort-icon">
            @props(['dir' => 'asc'])
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 opacity-70" viewBox="0 0 20 20" fill="currentColor">
                @if($dir === 'asc')
                    <path d="M5 12l5-5 5 5H5z" />
                @else
                    <path d="M5 8l5 5 5-5H5z" />
                @endif
            </svg>
        </x-slot>
    @endpush
@endonce