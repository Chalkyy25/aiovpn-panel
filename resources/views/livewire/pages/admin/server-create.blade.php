<x-slot name="header">
    <h2 class="text-xl font-semibold text-gray-800">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-4xl mx-auto space-y-6">

    {{-- Basic Fields --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Server Details</h3>

        <x-input label="Server Name" wire:model.live="name" />
        <x-input label="IP Address" wire:model.live="ip" class="mt-4" />
        <x-select label="Protocol" wire:model.live="protocol" :options="['OpenVPN' => 'OpenVPN', 'WireGuard' => 'WireGuard']" class="mt-4" />

        <div class="mt-4 grid grid-cols-2 gap-4">
            <x-input label="SSH Port" wire:model.live="sshPort" />
            <x-select label="SSH Login Type" wire:model.live="sshType" :options="['key' => 'SSH Key', 'password' => 'Password']" />
        </div>

        @if($sshType === 'key')
            <x-textarea label="SSH Public Key" wire:model.live="sshKey" class="mt-4" />
        @else
            <x-input label="SSH Password" wire:model.live="sshPassword" type="password" class="mt-4" />
        @endif
    </div>

    {{-- Advanced Config --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Advanced Settings</h3>

        <x-input label="OpenVPN Port" wire:model.live="port" />
        <x-select label="Transport Protocol" wire:model.live="transport" :options="['udp' => 'UDP', 'tcp' => 'TCP']" class="mt-4" />
        <x-input label="DNS Resolver (e.g. 1.1.1.1)" wire:model.live="dns" class="mt-4" />

        <div class="grid grid-cols-2 gap-4 mt-6">
            <x-checkbox label="Enable IPv6" wire:model.live="enableIPv6" />
            <x-checkbox label="Enable Logging" wire:model.live="enableLogging" />
            <x-checkbox label="Enable Proxy" wire:model.live="enableProxy" />
            <x-checkbox label="Custom Header 1" wire:model.live="header1" />
            <x-checkbox label="Custom Header 2" wire:model.live="header2" />
        </div>
    </div>

    {{-- Action & Feedback --}}
    <div class="p-6">
        @if (session()->has('status-message'))
            <div class="mb-4 text-green-600 font-semibold">
                {{ session('status-message') }}
            </div>
        @endif

        <div class="text-right">
            <x-button wire:click="create">
                🚀 Deploy Server
            </x-button>
        </div>
    </div>
</div>
