<div class="max-w-7xl mx-auto space-y-6">

    <form wire:submit.prevent="save" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT: Form --}}
        <div class="lg:col-span-2 space-y-6">

            <div class="p-6 bg-aio-card rounded-xl shadow">
                <h2 class="text-lg font-semibold mb-4">Create VPN User</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div>
                        <label class="block text-sm mb-1">Username</label>
                        <input wire:model.lazy="username"
                               class="w-full rounded-lg bg-aio-bg border border-white/10 px-3 py-2" />
                    </div>

                    <div>
                        <label class="block text-sm mb-1">Duration</label>
                        <select wire:model="expiry"
                                class="w-full rounded-lg bg-aio-bg border border-white/10 px-3 py-2">
                            <option value="1m">1 Month</option>
                            <option value="3m">3 Months</option>
                            <option value="6m">6 Months</option>
                            <option value="12m">12 Months</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm mb-1">Package</label>
                        <select wire:model="packageId"
                                class="w-full rounded-lg bg-aio-bg border border-white/10 px-3 py-2">
                            @foreach($packages as $p)
                                <option value="{{ $p->id }}">
                                    {{ $p->name }} — {{ $p->max_connections ?: 'Unlimited' }} device{{ $p->max_connections === 1 ? '' : 's' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                </div>
            </div>

            {{-- Servers --}}
            <div class="p-6 bg-aio-card rounded-xl shadow">
                <h3 class="font-medium mb-4">Assign Servers</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach($servers as $server)
                        <label class="flex items-center justify-between p-3 rounded-lg border border-white/10 cursor-pointer">
                            <div>
                                <div class="font-medium">{{ $server->name }}</div>
                                <div class="text-xs text-aio-sub">{{ $server->ip_address }}</div>
                            </div>

                            <input type="checkbox"
                                   wire:model="selectedServers"
                                   value="{{ $server->id }}"
                                   class="rounded border-white/20 bg-aio-bg">
                        </label>
                    @endforeach
                </div>
            </div>

        </div>

        {{-- RIGHT: Summary --}}
        <div class="space-y-4">

            <div class="p-6 bg-aio-card rounded-xl shadow">
                <h3 class="font-medium mb-4">Summary</h3>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span>Max connections</span>
                        <span class="font-medium">{{ $maxConnections }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Expires</span>
                        <span class="font-medium">{{ $expiresAtPreview }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Total cost</span>
                        <span class="font-medium">{{ $priceCredits }} credits</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Balance after</span>
                        <span class="font-medium">{{ $creditsLeft }}</span>
                    </div>
                </div>
            </div>

            <button type="submit"
                    class="w-full py-3 rounded-xl bg-aio-neon text-black font-semibold hover:opacity-90">
                Create User
            </button>

        </div>

    </form>
</div>