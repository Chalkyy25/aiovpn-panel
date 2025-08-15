{{-- resources/views/livewire/pages/admin/vpn-user-create.blade.php --}}
<div class="space-y-6">

    {{-- Flash + validation --}}
    @if ($errors->any())
        <div class="aio-section outline-mag">
            <h3 class="aio-section-title">Form errors</h3>
            <ul class="mt-2 list-disc pl-5 space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li class="text-red-300">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session()->has('success'))
        <div class="aio-section outline-neon">
            <div class="text-[var(--aio-ink)] font-medium">✅ {{ session('success') }}</div>
        </div>
    @endif

    {{-- STEP TABS --}}
    @php
        $canReview = filled($username) && in_array($expiry, ['1m','3m','6m','12m'], true)
                     && $packageId && is_array($selectedServers) && count($selectedServers) > 0;
        $canDone   = ($step === 3);
        $tabBase   = 'pb-2 font-semibold text-sm';
    @endphp

    <div class="aio-section">
        <div class="flex gap-6 border-b aio-divider">
            <button type="button"
                    wire:click="goTo(1)"
                    class="{{ $tabBase }} {{ $step === 1 ? 'text-white border-b-2 border-[var(--aio-pup)]' : 'text-[var(--aio-sub)] hover:text-white' }}">
                Details
            </button>

            <button type="button"
                    wire:click="goTo(2)"
                    @disabled(!$canReview)
                    class="{{ $tabBase }} {{ $step === 2 ? 'text-white border-b-2 border-[var(--aio-pup)]' : ($canReview ? 'text-[var(--aio-sub)] hover:text-white' : 'text-[var(--aio-sub)] opacity-50 cursor-not-allowed') }}">
                Review Purchase
            </button>

            <button type="button"
                    wire:click="goTo(3)"
                    @disabled(!$canDone)
                    class="{{ $tabBase }} {{ $step === 3 ? 'text-white border-b-2 border-[var(--aio-pup)]' : 'text-[var(--aio-sub)] opacity-50 cursor-not-allowed' }}">
                Done
            </button>
        </div>
    </div>

    {{-- STEP 1: DETAILS --}}
    @if ($step === 1)
        <div class="aio-section">
            <h3 class="aio-section-title">
                <span class="w-1.5 h-5 rounded accent-cya"></span>
                User & Plan
            </h3>
            <p class="aio-section-sub">Choose credentials, duration, package, and assign servers.</p>

            <div class="form-grid">
                {{-- Username --}}
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text"
                           class="form-input"
                           placeholder="Auto-generated if left as is"
                           wire:model.defer="username">
                    @error('username') <p class="aio-error text-red-300">{{ $message }}</p> @enderror
                </div>

                {{-- Duration --}}
                <div class="form-group">
                    <label class="form-label">Duration</label>
                    <select class="form-select" wire:model.defer="expiry">
                        <option value="1m">1 Month</option>
                        <option value="3m">3 Months</option>
                        <option value="6m">6 Months</option>
                        <option value="12m">12 Months</option>
                    </select>
                    @error('expiry') <p class="aio-error text-red-300">{{ $message }}</p> @enderror
                </div>

                {{-- Package --}}
                <div class="form-group md:col-span-2">
                    <label class="form-label">Package</label>
                    <select class="form-select" wire:model.defer="packageId">
                        @foreach($packages as $p)
                            <option value="{{ $p->id }}">
                                {{ $p->name }} — {{ $p->price_credits }} credits (max {{ $p->max_connections }} conn)
                            </option>
                        @endforeach
                    </select>
                    @error('packageId') <p class="aio-error text-red-300">{{ $message }}</p> @enderror

                    <div class="mt-2 text-xs muted space-y-0.5">
                        <div>Cost: <span class="text-[var(--aio-ink)] font-semibold">{{ $priceCredits }}</span> credits</div>
                        <div>Your credits: <span class="text-[var(--aio-ink)] font-semibold">{{ $adminCredits }}</span></div>
                        @if($adminCredits < $priceCredits)
                            <div class="text-red-300">Not enough credits for this package.</div>
                        @endif
                    </div>
                </div>

                {{-- Servers (MULTI‑SELECT CHECKBOXES) --}}
                <div class="form-group md:col-span-2">
                    <label class="form-label">Assign to Servers</label>

                    {{-- helpful tip --}}
                    <div class="aio-pill bg-white/10 mb-2">You can pick more than one server.</div>

                    <div class="grid gap-2 md:grid-cols-2">
                        @forelse($servers as $server)
                            <label class="form-check" wire:key="srv-{{ $server->id }}">
                                {{-- Important: name="selectedServers[]" + wire:model.defer="selectedServers" keeps array in sync --}}
                                <input type="checkbox"
                                       class="aio-checkbox"
                                       name="selectedServers[]"
                                       value="{{ $server->id }}"
                                       wire:model.defer="selectedServers">
                                <span class="truncate">
                                    {{ $server->name }}
                                    <span class="muted">({{ $server->ip_address }})</span>
                                </span>
                            </label>
                        @empty
                            <div class="muted">No servers available.</div>
                        @endforelse
                    </div>
                    @error('selectedServers') <p class="aio-error text-red-300 mt-2">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Step controls --}}
            <div class="mt-6 text-right">
                <button type="button"
                        wire:click="next"
                        @disabled(!$canReview || $adminCredits < $priceCredits)"
                        class="btn {{ (!$canReview || $adminCredits < $priceCredits) ? 'opacity-60 cursor-not-allowed' : '' }}">
                    Next
                </button>
            </div>
        </div>
    @endif

    {{-- STEP 2: REVIEW --}}
    @if ($step === 2)
        <div class="aio-section">
            <h3 class="aio-section-title">
                <span class="w-1.5 h-5 rounded accent-pup"></span>
                Review Purchase
            </h3>
            <p class="aio-section-sub">Confirm everything before spending credits.</p>

            <div class="grid gap-6 md:grid-cols-2">
                {{-- Summary --}}
                <div class="pill-card outline-cya p-4">
                    <h4 class="font-semibold mb-3">Summary</h4>
                    <dl class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <dt class="muted">Username</dt>
                            <dd class="font-mono">{{ $username }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="muted">Password</dt>
                            <dd class="muted italic">Will be generated on purchase</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="muted">Duration</dt>
                            <dd>
                                @switch($expiry)
                                    @case('1m') 1 Month @break
                                    @case('3m') 3 Months @break
                                    @case('6m') 6 Months @break
                                    @case('12m') 12 Months @break
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt class="muted mb-1">Servers</dt>
                            <dd>
                                @php $serverMap = $servers->keyBy('id'); @endphp
                                @if (count($selectedServers))
                                    <ul class="list-disc pl-5 space-y-1">
                                        @foreach ($selectedServers as $sid)
                                            @if ($serverMap->has($sid))
                                                <li>{{ $serverMap[$sid]->name }} ({{ $serverMap[$sid]->ip_address }})</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="muted">No servers selected</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Credits --}}
                <div class="pill-card outline-mag p-4">
                    <h4 class="font-semibold mb-3">Credits</h4>
                    @php $pkg = $packages->firstWhere('id', $packageId); @endphp
                    <div class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <span class="muted">Package</span>
                            <span>{{ $pkg?->name ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="muted">Cost</span>
                            <span>{{ $priceCredits }} credits</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="muted">Your balance</span>
                            <span class="{{ $adminCredits < $priceCredits ? 'text-red-300' : 'text-green-300' }}">
                                {{ $adminCredits }} credits
                            </span>
                        </div>
                        @if($adminCredits >= $priceCredits)
                            <div class="flex justify-between border-t aio-divider pt-2">
                                <span class="muted">Balance after</span>
                                <span>{{ $adminCredits - $priceCredits }} credits</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Step controls --}}
            <div class="mt-6 text-right space-x-3">
                <button type="button" wire:click="back" class="btn-secondary">Back</button>
                <button type="button"
                        wire:click="purchase"
                        @disabled($adminCredits < $priceCredits)"
                        class="btn {{ $adminCredits < $priceCredits ? 'opacity-60 cursor-not-allowed' : '' }}">
                    Purchase
                </button>
            </div>
        </div>
    @endif

    {{-- STEP 3: DONE --}}
    @if ($step === 3)
        <div class="aio-section text-center">
            <div class="text-4xl mb-2">🎉</div>
            <h3 class="text-xl font-semibold mb-1">VPN user created</h3>
            @if (session()->has('success'))
                <p class="muted mb-4">{{ session('success') }}</p>
            @endif
            <div class="flex items-center justify-center gap-3">
                <a href="{{ route('admin.vpn-users.index') }}" class="btn-secondary">View All Users</a>
                <button type="button" wire:click="$set('step', 1)" class="btn">Create Another</button>
            </div>
        </div>
    @endif
</div>