@php $isAdmin = auth()->user()?->role === 'admin'; @endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 [color-scheme:dark]">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-aio-ink">Create VPN User</h1>
            <p class="text-sm text-aio-sub">Username, duration, package and server assignment.</p>
        </div>
    </div>

    <form wire:submit.prevent="save" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Card: Details --}}
            <div class="rounded-2xl border border-white/10 bg-aio-card/70 backdrop-blur shadow-lg">
                <div class="p-5 border-b border-white/10">
                    <h2 class="text-sm font-semibold text-aio-ink">User details</h2>
                </div>

                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">

                    {{-- Username --}}
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-aio-sub mb-1">Username</label>
                        <input wire:model.lazy="username"
                               type="text"
                               class="w-full rounded-xl bg-black/30 border border-white/10 text-aio-ink placeholder:text-white/30
                                      focus:ring-2 focus:ring-aio-cya/50 focus:border-aio-cya/50"
                               placeholder="user-xxxxxx">
                        @error('username') <p class="mt-1 text-xs text-red-300">{{ $message }}</p> @enderror
                    </div>

                    {{-- Duration --}}
                    <div>
                        <label class="block text-xs font-medium text-aio-sub mb-1">Duration</label>
                        <select wire:model.live="expiry"
                                class="w-full rounded-xl bg-black/30 border border-white/10 text-aio-ink
                                       focus:ring-2 focus:ring-aio-cya/50 focus:border-aio-cya/50">
                            <option value="1m">1 Month</option>
                            <option value="3m">3 Months</option>
                            <option value="6m">6 Months</option>
                            <option value="12m">12 Months</option>
                        </select>
                        @error('expiry') <p class="mt-1 text-xs text-red-300">{{ $message }}</p> @enderror
                    </div>

                    {{-- Package --}}
                    <div>
                        <label class="block text-xs font-medium text-aio-sub mb-1">Package</label>
                        <select wire:model.live="packageId"
                                class="w-full rounded-xl bg-black/30 border border-white/10 text-aio-ink
                                       focus:ring-2 focus:ring-aio-cya/50 focus:border-aio-cya/50">
                            @foreach($packages as $p)
                                @php $dev = (int) $p->max_connections; @endphp
                                <option value="{{ $p->id }}">
                                    {{ $p->name }} — {{ $dev === 0 ? 'Unlimited' : $dev }} device{{ $dev === 1 ? '' : 's' }} — {{ (int)$p->price_credits }} cr/mo
                                </option>
                            @endforeach
                        </select>
                        @error('packageId') <p class="mt-1 text-xs text-red-300">{{ $message }}</p> @enderror
                    </div>

                </div>
            </div>

            {{-- Card: Servers --}}
            <div class="rounded-2xl border border-white/10 bg-aio-card/70 backdrop-blur shadow-lg">
                <div class="p-5 border-b border-white/10 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-aio-ink">Assign servers</h2>
                        <p class="text-xs text-aio-sub mt-0.5">Select one or more servers.</p>
                    </div>
                </div>

                <div class="p-5 space-y-3">
                    @error('selectedServers') <p class="text-sm text-red-300">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($servers as $server)
                            <label class="group flex items-center justify-between rounded-xl border border-white/10 bg-black/20 p-4
                                          hover:border-aio-cya/40 hover:bg-black/30 transition">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-aio-ink truncate">{{ $server->name }}</div>
                                    <div class="text-xs text-aio-sub truncate">{{ $server->ip_address }}</div>
                                </div>

                                <input type="checkbox"
                                       wire:model.live="selectedServers"
                                       value="{{ (string)$server->id }}"
                                       class="h-5 w-5 rounded-md border-white/20 bg-black/30 text-aio-cya focus:ring-aio-cya/40">
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>

        {{-- RIGHT --}}
        <div class="space-y-6">

            {{-- Summary --}}
            <div class="rounded-2xl border border-white/10 bg-aio-card/70 backdrop-blur shadow-lg">
                <div class="p-5 border-b border-white/10">
                    <h2 class="text-sm font-semibold text-aio-ink">Summary</h2>
                </div>

                <div class="p-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-aio-sub">Max connections</span>
                        <span class="font-semibold text-aio-ink">{{ $maxConnections ?? 0 }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-aio-sub">Expires</span>
                        <span class="font-semibold text-aio-ink">{{ $expiresAtPreview ?? '' }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-aio-sub">Total cost</span>
                        <span class="font-semibold text-aio-ink">{{ (int)($priceCredits ?? 0) }} credits</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-aio-sub">{{ $isAdmin ? 'Current credits' : 'Balance after' }}</span>
                        <span class="font-semibold text-aio-ink">{{ $isAdmin ? (int)$adminCredits : (int)($creditsLeft ?? 0) }}</span>
                    </div>

                    @if(!$isAdmin && (int)$adminCredits < (int)($priceCredits ?? 0))
                        <div class="mt-3 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-200">
                            Not enough credits for this purchase.
                        </div>
                    @endif

                    @if($isAdmin)
                        <div class="mt-3 text-xs text-green-300">
                            Admin — price shown, not deducted.
                        </div>
                    @endif
                </div>
            </div>

            <button type="submit"
                    class="w-full rounded-2xl px-4 py-3 font-semibold text-black bg-aio-neon hover:opacity-90
                           disabled:opacity-40 disabled:cursor-not-allowed"
                    wire:loading.attr="disabled">
                Create User
            </button>

        </div>
    </form>
</div>