    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800">➕ Add VPN Line</h2>
    </x-slot>

    <div class="p-6 max-w-2xl space-y-6 bg-white rounded shadow">
        @if (session()->has('message'))
            <div class="p-4 text-green-700 bg-green-100 rounded">{{ session('message') }}</div>
        @endif

        <x-input label="Username" wire:model.defer="username" />
        <x-input label="Password" wire:model.defer="password" type="password" />

        <x-select label="Expiry" wire:model.defer="expiry" :options="[
            '1m' => '1 Month',
            '3m' => '3 Months',
            '6m' => '6 Months',
            '12m' => '12 Months'
        ]" />

        <div>
            <label class="block font-semibold mb-2">Assign to Servers</label>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($servers as $server)
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}" />
                        <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                    </label>
                @endforeach
            </div>
        </div>

        <x-button wire:click="save">💾 Create VPN User</x-button>
    </div>
