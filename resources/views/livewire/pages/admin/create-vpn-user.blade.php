<div class="aio-card p-6 space-y-6" wire:poll.5s>

  {{-- Global errors --}}
  @if ($errors->any())
    <div class="aio-card bg-red-500/10 border-red-400/30 p-4">
      <h3 class="text-sm font-semibold text-red-300">Form errors</h3>
      <ul class="mt-2 list-disc pl-5 space-y-1 text-red-200 text-sm">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  {{-- Flash success --}}
  @if (session()->has('success'))
    <div class="aio-card bg-[rgba(61,255,127,.08)] border-[rgba(61,255,127,.25)] p-4 flex items-center gap-2">
      <span class="aio-pill pill-neon">✓</span>
      <span class="text-[var(--aio-ink)]">{{ session('success') }}</span>
    </div>
  @endif

  @php
    $canGoReview = filled($username) && count($selectedServers) > 0 && in_array($expiry, ['1m','3m','6m','12m']) && $packageId;
    $canGoDone   = ($step === 3);
  @endphp

  {{-- Step nav --}}
  <div class="flex items-center gap-6 text-sm font-semibold border-b aio-divider">
    <button type="button"
            wire:click="goTo(1)"
            class="pb-2 {{ $step===1 ? 'text-white border-b-2 border-[var(--aio-pup)]' : 'text-[var(--aio-sub)] hover:text-white' }}">
      Details
    </button>

    <button type="button"
            wire:click="goTo(2)"
            @disabled(!$canGoReview)
            class="pb-2 {{ $step===2 ? 'text-white border-b-2 border-[var(--aio-pup)]' : ($canGoReview ? 'text-[var(--aio-sub)] hover:text-white' : 'text-[var(--aio-sub)] opacity-50 cursor-not-allowed') }}">
      Review Purchase
    </button>

    <button type="button"
            wire:click="goTo(3)"
            @disabled(!$canGoDone)
            class="pb-2 {{ $step===3 ? 'text-white border-b-2 border-[var(--aio-pup)]' : ($canGoDone ? 'text-[var(--aio-sub)] hover:text-white' : 'text-[var(--aio-sub)] opacity-50 cursor-not-allowed') }}">
      Done
    </button>
  </div>

  {{-- STEP 1: DETAILS --}}
  @if ($step === 1)
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

      {{-- Username --}}
      <div class="form-group">
        <label class="form-label">Username</label>
        <input type="text" wire:model.lazy="username"
               placeholder="Auto-generated if left as is"
               class="form-input">
        @error('username') <p class="aio-error text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
      </div>

      {{-- Duration --}}
      <div class="form-group">
        <label class="form-label">Duration</label>
        <select wire:model="expiry" class="form-select">
          <option value="1m">1 Month</option>
          <option value="3m">3 Months</option>
          <option value="6m">6 Months</option>
          <option value="12m">12 Months</option>
        </select>
        @error('expiry') <p class="aio-error text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
      </div>

      {{-- Package --}}
      <div class="form-group">
        <label class="form-label">Package</label>
        <select wire:model="packageId" class="form-select">
          @foreach($packages as $p)
            <option value="{{ $p->id }}">
              {{ $p->name }} — {{ $p->price_credits }} credits (max {{ $p->max_connections }} conn)
            </option>
          @endforeach
        </select>
        @error('packageId') <p class="aio-error text-red-400 text-xs mt-1">{{ $message }}</p> @enderror>

        <div class="mt-2 text-xs muted space-y-0.5">
          <div>Cost: <span class="text-[var(--aio-ink)] font-semibold">{{ $priceCredits }}</span> credits</div>
          <div>Your credits: <span class="text-[var(--aio-ink)] font-semibold">{{ $adminCredits }}</span></div>
          @if($adminCredits < $priceCredits)
            <div class="text-red-300">Not enough credits for this package.</div>
          @endif
        </div>
      </div>

      {{-- Servers --}}
      <div class="form-group">
        <label class="form-label">Assign to Servers</label>
        <div class="space-y-2 aio-card p-3">
          @error('selectedServers') <p class="text-xs text-red-400 mb-2">{{ $message }}</p> @enderror
          @forelse ($servers as $server)
            <label class="form-check">
              <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}">
              <span class="muted">{{ $server->name }} ({{ $server->ip_address }})</span>
            </label>
          @empty
            <div class="muted text-sm">No servers available.</div>
          @endforelse
        </div>
      </div>

      {{-- Controls --}}
      <div class="md:col-span-2 text-right pt-2">
        <button type="button" wire:click="next" class="btn"
                @disabled($adminCredits < $priceCredits)>
          Next
        </button>
      </div>
    </div>
  @endif

  {{-- STEP 2: REVIEW --}}
  @if ($step === 2)
    <div class="space-y-6">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Summary --}}
        <div class="aio-card p-4">
          <h4 class="text-sm font-semibold mb-3">Summary</h4>
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
        <div class="aio-card p-4">
          <h4 class="text-sm font-semibold mb-3">Credits</h4>
          <div class="text-sm space-y-2">
            <div class="flex justify-between">
              <span class="muted">Package</span>
              <span>@php $pkg = $packages->firstWhere('id', $packageId); @endphp {{ $pkg?->name ?? '—' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="muted">Cost</span>
              <span>{{ $priceCredits }} credits</span>
            </div>
            <div class="flex justify-between">
              <span class="muted">Your balance</span>
              <span class="{{ $adminCredits < $priceCredits ? 'text-red-300' : 'text-[var(--aio-neon)]' }}">
                {{ $adminCredits }} credits
              </span>
            </div>
            @if($adminCredits >= $priceCredits)
              <div class="flex justify-between aio-divider pt-2">
                <span class="muted">Balance after</span>
                <span>{{ $adminCredits - $priceCredits }} credits</span>
              </div>
            @endif
          </div>
        </div>
      </div>

      {{-- Controls --}}
      <div class="text-right space-x-3">
        <button type="button" wire:click="back" class="btn-secondary">
          Back
        </button>
        <button type="button" wire:click="purchase" class="btn"
                @disabled($adminCredits < $priceCredits)>
          Purchase
        </button>
      </div>
    </div>
  @endif

  {{-- STEP 3: DONE --}}
  @if ($step === 3)
    <div class="text-center space-y-4 py-8">
      <div class="text-4xl">🎉</div>
      <h3 class="text-xl font-semibold">VPN user created</h3>

      @if (session()->has('success'))
        <p class="text-[var(--aio-neon)]">{{ session('success') }}</p>
      @endif

      <div class="flex items-center justify-center gap-3 mt-4">
        <a href="{{ route('admin.vpn-users.index') }}" class="btn">
          View All Users
        </a>
        <button type="button" wire:click="$set('step', 1)" class="btn-secondary">
          Create Another
        </button>
      </div>
    </div>
  @endif
</div>