<div class="bg-gray-900 text-white rounded shadow p-6">
    <!-- Tabs -->
    <div class="flex border-b border-gray-700 mb-6 text-sm font-semibold space-x-6">
        <button class="pb-2 border-b-2 border-blue-500 text-white">Details</button>
        <button class="pb-2 text-gray-400 hover:text-white">Restrictions</button>
        <button class="pb-2 text-gray-400 hover:text-white">Review Purchase</button>
    </div>

    <!-- Form -->
    <form wire:submit.prevent="create" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Username -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Username</label>
            <input type="text" wire:model="username"
                   placeholder="Leave blank to auto-generate"
                   class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" />
        </div>

        <!-- Password -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Password</label>
            <input type="text" wire:model="password"
                   placeholder="Leave blank to auto-generate"
                   class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500" />
        </div>

        <!-- Duration -->
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-300">Duration</label>
            <select wire:model="duration"
                    class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                <option value="1">1 Month</option>
                <option value="3">3 Months</option>
                <option value="6">6 Months</option>
                <option value="12">12 Months</option>
            </select>
        </div>

        <!-- Server Selection -->
        <div>
            <label class="block text-sm font-medium mb-2 text-gray-300">Assign to Servers</label>
            <div class="space-y-2">
                @foreach ($servers as $server)
                    <label class="flex items-center space-x-2 text-sm text-gray-300">
                        <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                               class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring focus:ring-blue-500" />
                        <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                    </label>
                @endforeach
            </div>
        </div>

        <!-- Submit Button -->
        <div class="md:col-span-2 text-right pt-4">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium">
                💾 Create VPN User
            </button>
        </div>
    </form>
</div>
