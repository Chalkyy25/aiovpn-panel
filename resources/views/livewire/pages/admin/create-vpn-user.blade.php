{{-- resources/views/livewire/pages/admin/create-vpn-user.blade.php --}}
<div class="space-y-6">
    {{-- Global errors --}}
    @if ($errors->any())
        <div class="aio-card p-4 border-red-500/30">
            <h3 class="text-sm font-semibold text-red-300">Form errors</h3>
            <ul class="mt-2 list-disc pl-5 space-y-1 text-red-200 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Success --}}
    @if (session()->has('success'))
        <div class="aio-card p-4">
            <span class="aio-pill pill-neon">✅</span>
            <span class="ml-2">{{ session('success') }}</span>
        </div>
    @endif

    @php $isAdmin = auth()->user()?->role === 'admin'; @endphp

    <form wire:submit.prevent="save" class="space-y-6">
        {{-- User Details --}}
        <section class="aio-section">
            <h3 class="aio-section-title">
                <span class="w-1.5 h-6 rounded accent-cya"></span> Create VPN User
            </h3>
            <p class="aio-section-sub">Choose username, duration, package & servers.</p>

            <div class="form-grid">
                {{-- Username --}}
                <div class="form-group md:col-span-2">
                    <label class="form-label">Username</label>
                    <input class="form-input"
                           type="text"
                           wire:model.lazy="username"
                           autocomplete="off"
                           autocorrect="off"
                           autocapitalize="off"
                           spellcheck="false" />
                    @error('username') <p class="text-red-300 text-xs">{{ $message }}</p> @enderror
                </div>

                {{-- Duration --}}
                <div class="form-group">
                    <label class="form-label">Duration</label>
                    <select class="form-select" wire:model.live="expiry">
                        <option value="1m">1 Month</option>
                        <option value="3m">3 Months</option>
                        <option value="6m">6 Months</option>
                        <option value="12m">12 Months (Yearly)</option>
                    </select>
                    @error('expiry') <p class="text-red-300 text-xs">{{ $message }}</p> @enderror
                </div>

                {{-- Package --}}
                <div class="form-group">
                    <label class="form-label">Package</label>
                    <select class="form-select" wire:model.live="packageId">
                        <option value="">Select one please</option>
                        @foreach($packages as $p)
                            @php
                                $devices = ($p->max_connections ?? 0) == 0 ? 'Unlimited' : (int) $p->max_connections;
                            @endphp
                            <option value="{{ $p->id }}">
                                {{ $p->name }} — {{ $devices }} device{{ $devices === 1 ? '' : 's' }} — {{ (int) $p->price_credits }} cr/mo
                            </option>
                        @endforeach
                    </select>
                    @error('packageId') <p class="text-red-300 text-xs">{{ $message }}</p> @enderror
                </div>

                {{-- IPTV-style preview fields --}}
                <div class="form-group">
                    <label class="form-label">Max Allowed Connections</label>
                    <input class="form-input" type="text" value="{{ $maxConnections ?? '' }}" readonly />
                </div>

                <div class="form-group">
                    <label class="form-label">Expire Date</label>
                    <input class="form-input" type="text" value="{{ $expiresAtPreview ?? '' }}" readonly />
                </div>

                <div class="form-group">
                    <label class="form-label">Price</label>
                    <input class="form-input" type="text" value="{{ (int) $priceCredits }}" readonly />
                </div>

                <div class="form-group">
                    <label class="form-label">Credits Left</label>
                    <input class="form-input" type="text" value="{{ (int) ($creditsLeft ?? 0) }}" readonly />
                </div>

                {{-- Credits / warnings --}}
                <div class="mt-2 text-xs muted space-y-0.5 md:col-span-2">
                    @if ($isAdmin)
                        <div class="text-green-300">Admin — no credits deducted.</div>
                    @else
                        <div>Your credits: <span class="font-semibold">{{ (int) $adminCredits }}</span></div>
                        <div>Cost: <span class="font-semibold">{{ (int) $priceCredits }}</span></div>

                        @if ((int) $adminCredits < (int) $priceCredits)
                            <div class="text-red-300">Not enough credits.</div>
                        @endif
                    @endif
                </div>
            </div>
        </section>

        {{-- Servers --}}
        <section class="aio-section">
            <div class="aio-section-title">Assign to Servers</div>
            <p class="aio-section-sub">Pick one or select ALL.</p>

            @error('selectedServers')
                <div class="mb-3 text-sm text-red-400">{{ $message }}</div>
            @enderror

            {{-- ALL SERVERS OPTION --}}
            @php $allId = 'srv-all'; @endphp
            <label for="{{ $allId }}"
                   class="pill-card cursor-pointer flex items-center justify-between p-3 hover:outline-cya mb-3">
                <div class="min-w-0">
                    <div class="font-medium truncate">All Servers</div>
                    <div class="text-xs muted">Automatically selects every enabled server</div>
                </div>

                <input id="{{ $allId }}"
                       type="checkbox"
                       class="sr-only peer"
                       wire:model.live="selectAllServers" />

                <div class="ml-3 h-5 w-5 rounded border"
                     style="border-color:rgba(255,255,255,.25)"></div>
            </label>

            <style>
                #{{ $allId }}:checked + div { background: var(--aio-neon); }
                label[for="{{ $allId }}"]:has(#{{ $allId }}:checked) {
                    box-shadow: inset 0 0 0 1px rgba(61,255,127,.35);
                }
            </style>

            {{-- INDIVIDUAL SERVERS --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($servers as $server)
                    @php $cbId = 'srv-'.$server->id; @endphp

                    <label wire:key="srv-{{ $server->id }}"
                           for="{{ $cbId }}"
                           class="pill-card cursor-pointer flex items-center justify-between p-3 hover:outline-cya">

                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ $server->name }}</div>
                            <div class="text-xs muted truncate">{{ $server->ip_address }}</div>
                        </div>

                        <input id="{{ $cbId }}"
                               type="checkbox"
                               class="sr-only peer"
                               value="{{ (string) $server->id }}"
                               wire:model.live="selectedServers"
                               @disabled($selectAllServers) />

                        <div class="ml-3 h-5 w-5 rounded border"
                             style="border-color:rgba(255,255,255,.25)"></div>
                    </label>

                    <style>
                        #{{ $cbId }}:checked + div { background: var(--aio-neon); }
                        label[for="{{ $cbId }}"]:has(#{{ $cbId }}:checked) {
                            box-shadow: inset 0 0 0 1px rgba(61,255,127,.35);
                        }
                    </style>
                @endforeach
            </div>

            <p class="form-help mt-3">Users can be connected to multiple servers.</p>
        </section>

        {{-- Actions --}}
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-button variant="light" :href="route('admin.vpn-users.index')">
                Cancel
            </x-button>

            <x-button type="submit" wire:loading.attr="disabled">
                {{ $isAdmin ? 'Save (Free)' : 'Save' }}
            </x-button>
        </div>
    </form>
</div>