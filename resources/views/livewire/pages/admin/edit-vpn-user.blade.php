<div class="bg-gray-900 text-white rounded shadow p-6">
    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-800 text-white rounded-md shadow">
            <h3 class="font-semibold">Form errors:</h3>
            <ul class="mt-2 list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <!-- Success Message -->
    @if (session()->has('success'))
        <div class="mb-4 p-4 bg-green-800 text-green-100 rounded-md shadow flex items-center">
            <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-white">Edit VPN User: {{ $vpnUser->username }}</h2>
        <p class="text-gray-400 mt-1">Update user details and server assignments</p>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-gray-700 mb-6 text-sm font-semibold space-x-6">
        <button class="pb-2 border-b-2 border-blue-500 text-white">Details</button>
        <button class="pb-2 text-gray-400 hover:text-white">Restrictions</button>
        <button class="pb-2 text-gray-400 hover:text-white">Review Changes</button>
    </div>

    <!-- Form -->
    <form wire:submit.prevent="save" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Username -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Username</label>
            <input type="text" wire:model.lazy="username"
                   placeholder="Enter username"
                   class="w-full bg-gray-800 border {{ $errors->has('username') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" />
            @error('username')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Password</label>
            <input type="text" wire:model.lazy="password"
                   placeholder="Leave blank to keep current password"
                   class="w-full bg-gray-800 border {{ $errors->has('password') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" />
            @error('password')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-xs text-gray-400">Current password: {{ $vpnUser->plain_password ?? 'Encrypted' }}</p>
        </div>

        <!-- Duration -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Duration</label>
            <select wire:model="expiry"
                    class="w-full bg-gray-800 border {{ $errors->has('expiry') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                <option value="1m">1 Month</option>
                <option value="3m">3 Months</option>
                <option value="6m">6 Months</option>
                <option value="12m">12 Months</option>
            </select>
            @error('expiry')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
            @if($vpnUser->expires_at)
                <p class="mt-1 text-xs text-gray-400">Current expiry: {{ $vpnUser->expires_at->format('d M Y') }}</p>
            @endif
        </div>

        <!-- Max Connections -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Max Connections</label>
            <input type="number" wire:model.lazy="maxConnections" min="1" max="10"
                   class="w-full bg-gray-800 border {{ $errors->has('maxConnections') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" />
            @error('maxConnections')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Active Status -->
        <div class="md:col-span-2">
            <label class="flex items-center space-x-2 text-sm text-gray-300">
                <input type="checkbox" wire:model="isActive"
                       class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring focus:ring-blue-500" />
                <span>User is active</span>
            </label>
            @error('isActive')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <!-- Extend Expiry -->
        <div class="md:col-span-2">
            <label class="flex items-center space-x-2 text-sm text-gray-300">
                <input type="checkbox" wire:model="extendExpiry"
                       class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring focus:ring-blue-500" />
                <span>Extend expiry date (if unchecked, current expiry date will be preserved)</span>
            </label>
            @error('extendExpiry')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-xs text-gray-400">
                ⚠️ Only check this if you want to extend the user's subscription from today.
                Leave unchecked to preserve the current expiry date when making other changes.
            </p>
        </div>

        <!-- Server Selection -->
        <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-2 text-gray-300">Assign to Servers</label>
            <div class="space-y-2 {{ $errors->has('selectedServers') ? 'border border-red-500 p-2 rounded' : '' }}">
            @error('selectedServers')
                <p class="mb-2 text-sm text-red-500">{{ $message }}</p>
            @enderror
                @foreach ($servers as $server)
                    <label class="flex items-center space-x-2 text-sm text-gray-300">
                        <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                               class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring focus:ring-blue-500" />
                        <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                        @if(in_array($server->id, $vpnUser->vpnServers->pluck('id')->toArray()))
                            <span class="text-xs text-green-400">(currently assigned)</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>

        <!-- Submit Button -->
        <div class="md:col-span-2 text-right pt-4 flex items-center justify-between">
            <a href="{{ route('admin.vpn-users.index') }}"
               class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded font-medium inline-flex items-center space-x-2">
                <span>←</span>
                <span>Back to Users</span>
            </a>

            <div class="flex items-center space-x-3">
                @if(session()->has('success'))
                    <a href="{{ route('admin.vpn-users.index') }}"
                       class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded font-medium inline-flex items-center space-x-2">
                        <span>✅</span>
                        <span>View All Users</span>
                    </a>
                @endif

                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium inline-flex items-center space-x-2"
                        wire:loading.attr="disabled"
                        wire:target="save">
                    <span wire:loading.remove wire:target="save">💾</span>
                    <svg wire:loading wire:target="save" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Update VPN User</span>
                </button>
            </div>
        </div>
    </form>
</div>
