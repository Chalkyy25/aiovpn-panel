<div class="p-6">
    <h2 class="text-xl font-bold">✏️ Edit VPN Server</h2>
    <p>You're editing server ID: {{ $vpnServer->id }}</p>
</div>
<div class="max-w-4xl mx-auto space-y-6">
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Server Details</h3>

        <x-input label="Server Name" wire:model.live="name" />
        <x-input label="IP Address" wire:model.live="ip_address" class="mt-4" />
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
    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-lg font-bold mb-4">Advanced Settings</h3>

        <x-input label="OpenVPN Port" wire:model.live="port" />
        <x-select label="Transport Protocol" wire:model.live="transport" :options="['udp' => 'UDP', 'tcp' => 'TCP']" class="mt-4" />
        <x-input label="DNS Resolver (e.g. 
    <div class="bg-white p-6 rounded shadow mt-6">
    <h3 class="text-lg font-bold mb-4">Deployment Log</h3>
    <livewire:admin.server-log :server="$vpnServer" />
</div>