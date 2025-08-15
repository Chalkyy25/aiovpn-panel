<div class="space-y-6" wire:poll.keep-alive>

  {{-- Global errors & flashes --}}
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

  @if (session()->has('success'))
    <div class="aio-card p-4">
      <span class="aio-pill pill-neon">✅</span>
      <span class="ml-2">{{ session('success') }}</span>
    </div>
  @endif

  {{-- Stepper --}}
  @php
    $canReview = filled($username) && count($selectedServers) > 0 && in_array($expiry,['1m','3m','6m','12m']) && $packageId;
    $canDone   = ($step === 3);
    $tab = function($is,$enabled=true){ 
      return 'pb-2 -mb-px border-b-2 '.
             ($is ? 'border-[var(--aio-pup)] text-white' : ($enabled ? 'border-transparent text-[var(--aio-sub)] hover:text-white' : 'border-transparent text-gray-500 cursor-not-allowed'));
    };
  @endphp

  <div class="flex items-center gap-6 text-sm font-semibold">
    <button type="button" class="{{ $tab($step===1) }}" wire:click="goTo(1)">Details</button>
    <button type="button" class="{{ $tab($step===2,$canReview) }}" @disabled(! $canReview) wire:click="goTo(2)">Review Purchase</button>
    <button type="button" class="{{ $tab($step===3,$canDone) }}"  @disabled(! $canDone)  wire:click="goTo(3)">Done</button>
  </div>

  {{-- STEP 1: DETAILS --}}
  @if ($step === 1)
    <section class="aio-section">
      <h3 class="aio-section-title"><span class="w-1.5 h-6 rounded accent-cya"></span> Details</h3>
      <p class="aio-section-sub">Choose username, package & target servers.</p>

      <div class="form-grid">
        {{-- Username --}}
        <div class="form-group md:col-span-2">
          <label class="form-label">Username</label>
          <input class="form-input" type="text" placeholder="Auto-generated if left as is"
                 wire:model.lazy="username">
          @error('username') <p class="aio-error text-red-300 text-xs">{{ $message }}</p> @enderror
        </div>

        {{-- Duration --}}
        <div class="form-group">
          <label class="form-label">Duration</label>
          <select class="form-select" wire:model.live="expiry">
            <option value="1m">1 Month</option>
            <option value="3m">3 Months</option>
            <option value="6m">6 Months</option>
            <option value="12m">12 Months</option>
          </select>
          @error('expiry') <p class="aio-error text-red-300 text-xs">{{ $message }}</p> @enderror
        </div>

        {{-- Package --}}
        <div class="form-group">
          <label class="form-label">Package</label>
          <select class="form-select" wire:model.live="packageId">
            @foreach($packages as $p)
              <option value="{{ $p->id }}">
                {{ $p->name }} — {{ $p->price_credits }} credits (max {{ $p->max_connections }} conn)
              </option>
            @endforeach
          </select>
          @error('packageId') <p class="aio-error text-red-300 text-xs">{{ $message }}</p> @enderror

          <div class="mt-2 text-xs muted space-y-0.5">
            <div>Cost: <span class="text-[var(--aio-ink)] font-semibold">{{ $priceCredits }}</span> credits</div>
            <div>Your credits: <span class="text-[var(--aio-ink)] font-semibold">{{ $adminCredits }}</span></div>
            @if($adminCredits < $priceCredits)
              <div class="text-red-300">Not enough credits for this package.</div>
            @endif
          </div>
        </div>

        {{-- Servers --}}
        <div class="md:col-span-2">
          <label class="form-label">Assign to Servers</label>

          @error('selectedServers')
            <div class="aio-pill bg-red-500/15 text-red-300 mb-2">{{ $message }}</div>
          @enderror

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach($servers as $server)
              @php $cid = 'srv-'.$server->id; @endphp
              <label for="{{ $cid }}" class="form-check aio-card p-3">
                <input id="{{ $cid }}"
                       name="selectedServers[]"
                       class="form-checkbox"
                       type="checkbox"
                       value="{{ (string)$server->id }}"
                       wire:model.live="selectedServers"
                       wire:key="srv-{{ $server->id }}">
                <span class="ml-1">
                  {{ $server->name }}
                  <span class="muted">({{ $server->ip_address }})</span>
                </span>
              </label>
            @endforeach
          </div>
          
         <div class="p-3 aio-section">
  <button type="button"
          wire:click="$set('step', 2)"
          class="btn">
    Test wire:click → step=2
  </button>
  <div class="mt-2 text-sm">Current step: {{ $step }}</div>
</div>

          <p class="form-help mt-2">You can select multiple servers.</p>
        </div>
      </div>

      <div class="mt-6 text-right">
        <button type="button"
                class="btn-secondary mr-2"
                wire:click="$refresh"
                wire:loading.attr="disabled">Refresh</button>

        <button type="button"
                class="btn"
                wire:click="next"
                wire:loading.attr="disabled">
          Next
        </button>
      </div>
    </section>
  @endif

  {{-- STEP 2: REVIEW --}}
  @if ($step === 2)
    <section class="aio-section">
      <h3 class="aio-section-title"><span class="w-1.5 h-6 rounded accent-pup"></span>Review</h3>
      <p class="aio-section-sub">Confirm details before purchase.</p>

      <div class="grid md:grid-cols-2 gap-6">
        <div class="aio-card p-4">
          <h4 class="font-semibold mb-3">Summary</h4>
          <dl class="text-sm space-y-2">
            <div class="flex justify-between"><dt class="muted">Username</dt><dd class="font-mono">{{ $username }}</dd></div>
            <div class="flex justify-between"><dt class="muted">Duration</dt>
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
                @if(count($selectedServers))
                  <ul class="list-disc pl-5 space-y-1">
                    @foreach($selectedServers as $sid)
                      @if($serverMap->has($sid))
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

        <div class="aio-card p-4">
          <h4 class="font-semibold mb-3">Credits</h4>
          @php $pkg = $packages->firstWhere('id',$packageId); @endphp
          <div class="text-sm space-y-2">
            <div class="flex justify-between"><span class="muted">Package</span><span>{{ $pkg?->name ?? '—' }}</span></div>
            <div class="flex justify-between"><span class="muted">Cost</span><span>{{ $priceCredits }} credits</span></div>
            <div class="flex justify-between">
              <span class="muted">Your balance</span>
              <span class="{{ $adminCredits < $priceCredits ? 'text-red-300' : 'text-green-300' }}">{{ $adminCredits }} credits</span>
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

      <div class="mt-6 text-right">
        <button type="button" class="btn-secondary mr-2" wire:click="back" wire:loading.attr="disabled">Back</button>
        <button type="button" class="btn" wire:click="purchase" wire:loading.attr="disabled" @disabled($adminCredits < $priceCredits)>
          Purchase
        </button>
      </div>
    </section>
  @endif

  {{-- STEP 3: DONE --}}
  @if ($step === 3)
    <section class="aio-section text-center">
      <div class="text-4xl mb-2">🎉</div>
      <h3 class="text-xl font-semibold mb-2">VPN user created</h3>
      @if (session()->has('success'))
        <p class="muted">{{ session('success') }}</p>
      @endif>

      <div class="mt-6 flex items-center justify-center gap-3">
        <a href="{{ route('admin.vpn-users.index') }}" class="btn-secondary">View All Users</a>
        <button type="button" class="btn" wire:click="$set('step', 1)">Create Another</button>
      </div>
    </section>
  @endif
</div>