<div class="max-w-xl mx-auto p-6 bg-white shadow rounded space-y-5">
    <h2 class="text-xl font-semibold">Create New VPN Client</h2>

    @if (session()->has('success'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit.prevent="save" class="space-y-4">
        <!-- Username (optional) -->
        <div>
            <x-label for="username" value="Username (optional)" />
            <x-input id="username" type="text" wire:model.defer="username" class="w-full" placeholder="Leave blank for random" />
            @error('username') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <!-- VPN Servers Multi-select -->
<div>
    <x-label for="selectedServers" value="Assign VPN Servers" />
    <select id="selectedServers" wire:model.defer="selectedServers" multiple class="w-full">
        @foreach($vpnServers as $server)
            <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->ip_address }})</option>
        @endforeach
    </select>
    @error('selectedServers') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
</div>

        <!-- Submit -->
        <div class="flex justify-end">
            <x-button type="submit" class="px-5">Create VPN Client</x-button>
        </div>
    </form>
</div>
