<div class="max-w-xl mx-auto p-6 bg-white shadow rounded">
    <h2 class="text-xl font-semibold mb-4">Edit VPN Server</h2>

    @if (session()->has('status-message'))
        <div class="mb-4 text-green-600">
            {{ session('status-message') }}
        </div>
    @endif

    <form wire:submit.prevent="save">
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Name</label>
            <input type="text" wire:model.defer="name" class="w-full border rounded px-3 py-2" />
            @error('name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">IP Address</label>
            <input type="text" wire:model.defer="ip" class="w-full border rounded px-3 py-2" />
            @error('ip') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Protocol</label>
            <select wire:model.defer="protocol" class="w-full border rounded px-3 py-2">
                <option value="OpenVPN">OpenVPN</option>
                <option value="WireGuard">WireGuard</option>
            </select>
            @error('protocol') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">Status</label>
            <select wire:model.defer="status" class="w-full border rounded px-3 py-2">
                <option value="online">Online</option>
                <option value="offline">Offline</option>
            </select>
            @error('status') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Save Changes
            </button>
        </div>
    </form>
</div>
