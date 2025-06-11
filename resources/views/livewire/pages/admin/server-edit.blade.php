<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">Edit VPN Server</h2>
    </x-slot>

    <div class="max-w-3xl mx-auto py-6">
        <div class="bg-white p-6 rounded shadow">
            <x-input label="Name" wire:model.live="name" class="mb-4" />
            <x-input label="IP Address" wire:model.live="ip" class="mb-4" />
            <x-select label="Protocol" wire:model.live="protocol" :options="['OpenVPN' => 'OpenVPN', 'WireGuard' => 'WireGuard']" class="mb-4" />
            <x-select label="Status" wire:model.live="status" :options="['online' => 'Online', 'offline' => 'Offline']" class="mb-4" />

            <div class="text-right">
                <x-button wire:click="save">💾 Save Changes</x-button>
            </div>
        </div>
    </div>
</x-app-layout>
