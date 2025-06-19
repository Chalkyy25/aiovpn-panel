<x-slot name="header">
    <h2 class="text-xl font-semibold text-gray-800">Create New VPN Server</h2>
</x-slot>

<div class="py-6 max-w-4xl mx-auto space-y-6">

    {{-- Debug flash --}}
    @if (session()->has('debug'))
        <div class="p-4 bg-yellow-100 text-black rounded">
            {{ session('debug') }}
        </div>
    @endif

    {{-- Basic Fields --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Server Details</h3>

        <x-input label="Server Name" wire:model.defer="name" />
        <x-input label="IP Address" wire:model.defer="ip" class="mt-4" />
        <x-select label="Protocol" wire:model.defer="protocol" :options="['OpenVPN' => 'OpenVPN', 'WireGuard' => 'WireGuard']" class="mt-4" />

        <div class="mt-4 grid grid-cols-2 gap-4">
            <x-input label="SSH Port" wire:model.defer="sshPort" />
            <x-select label="SSH Login Type" wire:model.defer="sshType" :options="['key' => 'SSH Key', 'password' => 'Password']" />
        </div>

        @if($sshType === 'key')
            <div class="mt-4 text-sm text-gray-600">
                ✅ Using default SSH key <code>id_rsa</code> (already stored on the panel server)
            </div>
        @else
            <x-input label="SSH Password" wire:model.defer="sshPassword" type="password" class="mt-4" />
        @endif
    </div>

    {{-- Advanced Config --}}
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Advanced Settings</h3>

        <x-input label="OpenVPN Port" wire:model.defer="port" />
        <x-select label="Transport Protocol" wire:model.defer="transport" :options="['udp' => 'UDP', 'tcp' => 'TCP']" class="mt-4" />
        <x-input label="DNS Resolver (e.g. 1.1.1.1)" wire:model.defer="dns" class="mt-4" />

        <div class="grid grid-cols-2 gap-4 mt-6">
            <x-checkbox label="Enable IPv6" wire:model.defer="enableIPv6" />
            <x-checkbox label="Enable Logging" wire:model.defer="enableLogging" />
            <x-checkbox label="Enable Proxy" wire:model.defer="enableProxy" />
            <x-checkbox label="Custom Header 1" wire:model.defer="header1" />
            <x-checkbox label="Custom Header 2" wire:model.defer="header2" />
        </div>
    </div>

{{-- Action & Feedback --}}
<div class="p-6">

    @if (session()->has('status-message'))
        <div class="mb-4 text-green-600 font-semibold">
            {{ session('status-message') }}
        </div>
    @endif

    @if ($serverId)
        <div wire:poll.2s="refreshLog" class="bg-black text-green-400 font-mono p-4 rounded mb-4 h-48 overflow-y-auto text-xs">
            {!! nl2br(e($deploymentLog)) !!}
        </div>
    @endif

    <div class="text-right mt-4">
        <x-button wire:click="create">🚀 Deploy Server</x-button>
    </div>
</div>

{{-- keep outside Livewire markup so it’s injected only once --}}
@push('scripts')
<script>
    document.addEventListener('livewire:update', () => {
        const box = document.getElementById('deploy-log');
        if (box) box.scrollTop = box.scrollHeight;
    });
</script>
@endpush
