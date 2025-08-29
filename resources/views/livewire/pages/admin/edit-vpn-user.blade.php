{{-- …header, errors, success… --}}

<form wire:submit.prevent="save" class="grid grid-cols-1 md:grid-cols-2 gap-6">
  {{-- Username --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-gray-300">Username</label>
    <input type="text" wire:model.lazy="username" autocomplete="off"
           class="w-full bg-gray-800 border {{ $errors->has('username') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2" />
    @error('username') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
  </div>

  {{-- Password (leave blank to keep) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-gray-300">Password</label>
    <input type="text" wire:model.lazy="password" autocomplete="new-password"
           placeholder="Leave blank to keep current password"
           class="w-full bg-gray-800 border {{ $errors->has('password') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2" />
    @error('password') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    <p class="mt-1 text-xs text-gray-400">Current password: {{ $vpnUser->plain_password ?? 'Encrypted' }}</p>
  </div>

  {{-- Package --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-gray-300">Package</label>
    <select class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2" wire:model="packageId">
      <option value="">— No package (manual) —</option>
      @foreach($packages as $p)
        <option value="{{ $p->id }}">
          {{ $p->name }} — {{ $p->price_credits }} credits
          (max {{ $p->max_connections == 0 ? 'Unlimited' : $p->max_connections }} conn)
        </option>
      @endforeach
    </select>
    <p class="text-xs mt-1 text-gray-400">
      Selecting a package sets <strong>Max Connections</strong>. Leave blank to edit manually.
    </p>
  </div>

  {{-- Max Connections (single field; 0 = Unlimited) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-gray-300">Max Connections</label>
    <input type="number" min="0" wire:model.lazy="maxConnections"
           class="w-full bg-gray-800 border {{ $errors->has('maxConnections') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2" />
    <p class="text-xs mt-1 text-gray-400">Use 0 for Unlimited devices.</p>
    @error('maxConnections') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
  </div>

  {{-- Renewal term (used only if expired) --}}
  <div>
    <label class="block text-sm font-medium mb-1 text-gray-300">Renewal Term</label>
    <select wire:model="expiry"
            class="w-full bg-gray-800 border {{ $errors->has('expiry') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2">
      <option value="1m">1 Month</option>
      <option value="3m">3 Months</option>
      <option value="6m">6 Months</option>
      <option value="12m">12 Months</option>
    </select>
    @error('expiry') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    @if($vpnUser->expires_at)
      <p class="mt-1 text-xs text-gray-400">
        Current expiry: {{ $vpnUser->expires_at->format('d M Y') }} — this date will only change if the user is expired.
      </p>
    @endif
  </div>

  {{-- Active --}}
  <div class="md:col-span-2">
    <label class="flex items-center space-x-2 text-sm text-gray-300">
      <input type="checkbox" wire:model="isActive"
             class="text-blue-500 bg-gray-700 border-gray-600 rounded" />
      <span>User is active</span>
    </label>
  </div>

  {{-- Servers --}}
  <div class="md:col-span-2">
    <label class="block text-sm font-medium mb-2 text-gray-300">Assign to Servers</label>
    @error('selectedServers') <p class="mb-2 text-sm text-red-500">{{ $message }}</p> @enderror
    <div class="space-y-2">
      @foreach ($servers as $server)
        <label class="flex items-center space-x-2 text-sm text-gray-300">
          <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                 class="text-blue-500 bg-gray-700 border-gray-600 rounded" />
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
    <button type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium"
            wire:loading.attr="disabled">Update VPN User</button>
  </div>
</form>