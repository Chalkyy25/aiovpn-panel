{{-- …header, errors, success… --}}

<form wire:submit.prevent="save" class="grid grid-cols-1 md:grid-cols-2 gap-6">
  {{-- Username --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Username</label>
    <input type="text" wire:model.lazy="username" autocomplete="off" class="form-input w-full" />
    @error('username') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
  </div>

  {{-- Password (leave blank to keep) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Password</label>
    <input type="text" wire:model.lazy="password" autocomplete="new-password"
           placeholder="Leave blank to keep current password" class="form-input w-full" />
    @error('password') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    <p class="mt-1 text-xs text-[var(--aio-sub)]">Current password: {{ $vpnUser->plain_password ?? 'Encrypted' }}</p>
  </div>

  {{-- Package --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Package</label>
    <select class="form-select w-full" wire:model="packageId">
      <option value="">— No package (manual) —</option>
      @foreach($packages as $p)
        <option value="{{ $p->id }}">
          {{ $p->name }} — {{ $p->price_credits }} credits
          (max {{ $p->max_connections == 0 ? 'Unlimited' : $p->max_connections }} conn)
        </option>
      @endforeach
    </select>
    <p class="text-xs mt-1 text-[var(--aio-sub)]">
      Selecting a package sets <strong>Max Connections</strong>. Leave blank to edit manually.
    </p>
  </div>

  {{-- Max Connections (single field; 0 = Unlimited) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Max Connections</label>
    <input type="number" min="0" wire:model.lazy="maxConnections" class="form-input w-full" />
    <p class="text-xs mt-1 text-[var(--aio-sub)]">Use 0 for Unlimited devices.</p>
    @error('maxConnections') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
  </div>

  {{-- Renewal term (used only if expired) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Renewal Term</label>
    <select wire:model="expiry" class="form-select w-full">
      <option value="1m">1 Month</option>
      <option value="3m">3 Months</option>
      <option value="6m">6 Months</option>
      <option value="12m">12 Months</option>
    </select>
    @error('expiry') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    @if($vpnUser->expires_at)
      <p class="mt-1 text-xs text-[var(--aio-sub)]">
        Current expiry: {{ $vpnUser->expires_at->format('d M Y') }} — this date will only change if the user is expired.
      </p>
    @endif
  </div>

  {{-- Active --}}
  <div class="md:col-span-2">
    <label class="flex items-center space-x-2 text-sm text-[var(--aio-ink)]">
      <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-[var(--aio-border)]" />
      <span>User is active</span>
    </label>
  </div>

  {{-- Servers --}}
  <div class="md:col-span-2">
    <label class="block text-sm font-medium mb-2 text-[var(--aio-sub)]">Assign to Servers</label>
    @error('selectedServers') <p class="mb-2 text-sm text-red-500">{{ $message }}</p> @enderror
    <div class="space-y-2">
      @foreach ($servers as $server)
        <label class="flex items-center space-x-2 text-sm text-[var(--aio-ink)]">
          <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                 class="h-4 w-4 rounded border-[var(--aio-border)]" />
          <span>{{ $server->name }} ({{ $server->ip_address }})</span>
          @if(in_array($server->id, $vpnUser->vpnServers->pluck('id')->toArray()))
            <span class="text-xs text-green-400">(currently assigned)</span>
          @endif
        </label>
      @endforeach
    </div>
  </div>

  {{-- Actions --}}
  <div class="md:col-span-2 text-right pt-4">
    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Update VPN User</x-button>
  </div>
</form>