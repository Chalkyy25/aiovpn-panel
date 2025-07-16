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
            <label for="username" class="block text-sm font-medium text-gray-700">Username (optional)</label>
            <input id="username" type="text" wire:model.defer="username" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="Leave blank for random" />
            @error('username') 
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p> 
            @enderror
        </div>

        <!-- Device Name -->
        <div>
            <label for="deviceName" class="block text-sm font-medium text-gray-700">Device Name</label>
            <input id="deviceName" type="text" wire:model.defer="deviceName" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="e.g. John's iPhone" />
            @error('deviceName') 
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p> 
            @enderror
        </div>

        <!-- VPN Servers Multi-select -->
        <div>
            <label for="selectedServers" class="block text-sm font-medium text-gray-700">Assign VPN Servers</label>
            <select id="selectedServers" wire:model.defer="selectedServers" multiple class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                @foreach($vpnServers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->ip_address }})</option>
                @endforeach
            </select>
            @error('selectedServers') 
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p> 
            @enderror
        </div>

        <!-- Submit -->
        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Create VPN Client
            </button>
        </div>
    </form>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const deviceName = navigator.userAgent;
        @this.set('deviceName', deviceName);
    });
</script>

</div>
