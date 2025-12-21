<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-2xl font-bold text-[var(--aio-ink)]">Resellers</h2>

        <div class="flex items-center gap-3">
            <input
                type="text"
                wire:model.debounce.300ms="search"
                placeholder="Search name or email…"
                class="form-input"
            />
        </div>
    </div>

    {{-- Desktop / tablet table (scrolls horizontally) --}}
    <div class="hidden md:block">
        <div class="overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <div class="aio-card overflow-hidden">
                    <table class="aio-table min-w-[980px] table-fixed">
                        <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider">
                            <th class="w-[32%] px-4 py-3">
                                <button wire:click="sort('name')" class="inline-flex items-center gap-1">
                                    Name / Email
                                    @if($sortBy==='name')
                                        <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="w-[12%] px-4 py-3">
                                <button wire:click="sort('credits')" class="inline-flex items-center gap-1">
                                    Credits
                                    @if($sortBy==='credits')
                                        <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="w-[14%] px-4 py-3">
                                <button wire:click="sort('lines_count')" class="inline-flex items-center gap-1">
                                    # of Lines
                                    @if($sortBy==='lines_count')
                                        <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="w-[18%] px-4 py-3">
                                <button wire:click="sort('last_login_at')" class="inline-flex items-center gap-1">
                                    Last Login
                                    @if($sortBy==='last_login_at')
                                        <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="w-[12%] px-4 py-3">
                                <button wire:click="sort('is_active')" class="inline-flex items-center gap-1">
                                    Status
                                    @if($sortBy==='is_active')
                                        <span class="text-[10px]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="w-[12%] px-4 py-3 text-right">Actions</th>
                        </tr>
                        </thead>

                        <tbody>
                        @forelse($resellers as $r)
                            <tr class="hover:bg-[var(--aio-hover)]">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full {{ $r->is_active ? 'bg-green-500' : 'bg-[var(--aio-border)]' }}"></span>
                                        <div>
                                            <div class="font-medium text-[var(--aio-ink)]">{{ $r->name ?? '—' }}</div>
                                            <div class="text-sm text-[var(--aio-sub)]">{{ $r->email }}</div>
                                            <div class="text-xs text-[var(--aio-sub)]">ID: {{ $r->id }}</div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex min-w-[2.5rem] items-center justify-center rounded-md bg-[var(--aio-accent)]/10 px-2 py-1 text-sm font-semibold text-[var(--aio-accent)]">
                                        {{ (int) $r->credits }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex min-w-[2.5rem] items-center justify-center rounded-md bg-[var(--aio-accent)]/10 px-2 py-1 text-sm font-semibold text-[var(--aio-accent)]">
                                        {{ (int) ($r->lines_count ?? 0) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-sm text-[var(--aio-ink)]">
                                    @if(!empty($r->last_login_at))
                                        {{ \Carbon\Carbon::parse($r->last_login_at)->diffForHumans() }}
                                    @else
                                        <span class="text-[var(--aio-sub)]">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    @if($r->is_active)
                                        <span class="aio-pill pill-success">Active</span>
                                    @else
                                        <span class="aio-pill">Disabled</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-button
                                            href="{{ route('admin.resellers.index') }}?impersonate={{ $r->id }}"
                                            variant="secondary"
                                            size="sm">
                                            View
                                        </x-button>
                                        <x-button
                                            href="{{ route('admin.credits') }}?user={{ $r->id }}"
                                            variant="primary"
                                            size="sm">
                                            Credits
                                        </x-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-[var(--aio-sub)]">
                                    No resellers found.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Pagination --}}
        <div class="mt-3">
            {{ $resellers->links() }}
        </div>
    </div>

    {{-- Mobile card list --}}
    <div class="md:hidden space-y-3">
        @forelse($resellers as $r)
            <div class="aio-card">
                <div class="aio-card-body">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full {{ $r->is_active ? 'bg-green-500' : 'bg-[var(--aio-border)]' }}"></span>
                        <div>
                            <div class="font-semibold text-[var(--aio-ink)]">{{ $r->name ?? '—' }}</div>
                            <div class="text-sm text-[var(--aio-ink)]">{{ $r->email }}</div>
                            <div class="text-xs text-[var(--aio-sub)]">ID: {{ $r->id }}</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-[var(--aio-sub)]">Credits</div>
                        <div class="font-semibold text-[var(--aio-ink)]">{{ (int) $r->credits }}</div>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md bg-[var(--aio-accent)]/10 px-3 py-2 text-[var(--aio-accent)]">
                        <div class="text-xs opacity-80">Lines</div>
                        <div class="font-semibold">{{ (int) ($r->lines_count ?? 0) }}</div>
                    </div>
                    <div class="rounded-md bg-[var(--aio-hover)] px-3 py-2 text-[var(--aio-ink)]">
                        <div class="text-xs opacity-80">Last Login</div>
                        <div class="font-medium">
                            @if(!empty($r->last_login_at))
                                {{ \Carbon\Carbon::parse($r->last_login_at)->diffForHumans() }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-end gap-2">
                    <x-button
                        href="{{ route('admin.resellers.index') }}?impersonate={{ $r->id }}"
                        variant="secondary"
                        size="sm">
                        View
                    </x-button>
                    <x-button
                        href="{{ route('admin.credits') }}?user={{ $r->id }}"
                        variant="primary"
                        size="sm">
                        Credits
                    </x-button>
                </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-[var(--aio-border)] bg-[var(--aio-hover)] p-8 text-center text-sm text-[var(--aio-sub)]">
                No resellers found.
            </div>
        @endforelse

        {{-- Pagination --}}
        <div class="mt-3">
            {{ $resellers->links() }}
        </div>
    </div>
</div>