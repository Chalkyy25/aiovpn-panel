<x-slot name="header">
    <h2 class="text-2xl text-white font-semibold">➕ Add VPN Line</h2>
</x-slot>

<div class="bg-gray-900 text-gray-200 p-6 rounded shadow-sm">
    {{-- Tab Bar --}}
    <div class="mb-6 border-b border-gray-700">
        <nav class="flex space-x-6 text-sm font-medium">
            <a href="#" class="text-white border-b-2 border-blue-500 pb-2">Details</a>
            <a href="#" class="text-gray-400 hover:text-white pb-2">Restrictions</a>
            <a href="#" class="text-gray-400 hover:text-white pb-2">Review Purchase</a>
        </nav>
    </div>

    {{-- Form --}}
    <form wire:submit.prevent="create" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm mb-1">Username</label>
            <input type="text" wire:model="username" placeholder="Leave blank to auto-generate"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white" />
        </div>

        <div>
            <label class="block text-sm mb-1">Password</label>
            <input type="text" wire:model="password" placeholder="Leave blank to auto-generate"
                   class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white" />
        </div>

        <div>
            <label class="block text-sm mb-1">Duration</label>
            <select wire:model="duration"
                    class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white">
                <option value="1">1 Month</option>
                <option value="3">3 Months</option>
                <option value="12">12 Months</option>
            </select>
        </div>

        <div>
            <label class="block text-sm mb-1">Assign to Servers</label>
            <div class="space-y-2">
                @foreach($servers as $server)
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                               class="text-blue-500 bg-gray-800 border-gray-600 rounded" />
                        <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="md:col-span-2">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 px-6 py-2 rounded text-white font-semibold float-right">
                💾 Create VPN User
            </button>
        </div>
    </form>
</div>
