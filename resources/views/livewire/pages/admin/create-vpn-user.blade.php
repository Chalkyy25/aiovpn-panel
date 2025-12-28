@php $isAdmin = auth()->user()?->role === 'admin'; @endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-[var(--aio-ink)]">Create VPN User</h1>
            <p class="text-sm text-[var(--aio-sub)]">Username, package and server assignment.</p>
        </div>
    </div>

    <form wire:submit.prevent="save" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Card: Details --}}
            <div class="aio-card">
                <div class="aio-card-header">
                    <h2 class="text-sm font-semibold text-[var(--aio-ink)]">User details</h2>
                </div>

                <div class="aio-card-body space-y-4">

                    {{-- Username --}}
                    <div>
                        <label class="block text-xs font-medium text-[var(--aio-sub)] mb-1">Username</label>
                        <input wire:model.lazy="username" type="text" class="form-input w-full" placeholder="user-xxxxxx">
                        @error('username') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>

                    {{-- Package --}}
                    <div>
                        <label class="block text-xs font-medium text-[var(--aio-sub)] mb-1">Package</label>
                        <select wire:model.live="packageId" class="form-select w-full">
                            @foreach($packages as $p)
                                @php
                                    $dev = (int) $p->max_connections;
                                    $months = (int) $p->duration_months;
                                    $totalCredits = $months * (int)$p->price_credits;
                                @endphp
                                <option value="{{ $p->id }}">
                                    {{ $p->name }} — {{ $months }} month{{ $months === 1 ? '' : 's' }} — {{ $dev === 0 ? 'Unlimited' : $dev }} device{{ $dev === 1 ? '' : 's' }} — {{ $totalCredits }} credits
                                </option>
                            @endforeach
                        </select>
                        @error('packageId') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>

                </div>
            </div>

            {{-- Card: Servers --}}
            <div class="aio-card">
                <div class="aio-card-header flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-[var(--aio-ink)]">Assign servers</h2>
                        <p class="text-xs text-[var(--aio-sub)] mt-0.5">Select one or more servers.</p>
                    </div>
                </div>

                <div class="aio-card-body space-y-3">
                    @error('selectedServers') <p class="text-sm text-red-400">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($servers as $server)
                            <label class="group flex items-center justify-between rounded-xl border border-[var(--aio-border)] bg-[var(--aio-hover)] p-4
                                          hover:border-[var(--aio-accent)] hover:bg-[var(--aio-accent)]/5 transition cursor-pointer">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-[var(--aio-ink)] truncate">{{ $server->name }}</div>
                                    <div class="text-xs text-[var(--aio-sub)] truncate">{{ $server->ip_address }}</div>
                                </div>

                                <input type="checkbox"
                                       wire:model.live="selectedServers"
                                       value="{{ (string)$server->id }}"
                                       class="h-5 w-5 rounded-md border-[var(--aio-border)]">
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>

        {{-- RIGHT --}}
        <div class="space-y-6">

            {{-- Summary --}}
            <div class="aio-card">
                <div class="aio-card-header">
                    <h2 class="text-sm font-semibold text-[var(--aio-ink)]">Summary</h2>
                </div>

                <div class="aio-card-body space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--aio-sub)]">Max connections</span>
                        <span class="font-semibold text-[var(--aio-ink)]">{{ $maxConnections ?? 0 }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-[var(--aio-sub)]">Expires</span>
                        <span class="font-semibold text-[var(--aio-ink)]">{{ $expiresAtPreview ?? '' }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-[var(--aio-sub)]">Total cost</span>
                        <span class="font-semibold text-[var(--aio-ink)]">{{ (int)($priceCredits ?? 0) }} credits</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-[var(--aio-sub)]">{{ $isAdmin ? 'Current credits' : 'Balance after' }}</span>
                        <span class="font-semibold text-[var(--aio-ink)]">{{ $isAdmin ? (int)$adminCredits : (int)($creditsLeft ?? 0) }}</span>
                    </div>

                    @if(!$isAdmin && (int)$adminCredits < (int)($priceCredits ?? 0))
                        <div class="mt-3 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-300">
                            Not enough credits for this purchase.
                        </div>
                    @endif

                    @if($isAdmin)
                        <div class="mt-3 text-xs text-green-400">
                            Admin — price shown, not deducted.
                        </div>
                    @endif
                </div>
            </div>

            <x-button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                Create User
            </x-button>

        </div>
    </form>
</div>
