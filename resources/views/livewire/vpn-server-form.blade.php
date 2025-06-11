<div class="max-w-xl mx-auto p-4 bg-white rounded shadow space-y-4">
    @if (session()->has('success'))
        <div class="text-green-600">{{ session('success') }}</div>
    @endif

    <div>
        <label class="block font-bold">Name</label>
        <input wire:model.live="name" class="w-full border p-2 rounded" type="text" />
        @error('name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
    </div>

    <div>
        <label class="block font-bold">IP Address</label>
        <input wire:model.live="ip" class="w-full border p-2 rounded" type="text" />
        @error('ip') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
    </div>

    <div>
        <label class="block font-bold">SSH Port</label>
        <input wire:model.live="port" class="w-full border p-2 rounded" type="number" />
        @error('port') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
    </div>

    <div>
        <label class="block font-bold">Login Type</label>
        <select wire:model.live="loginType" class="w-full border p-2 rounded">
            <option value="ssh_key">SSH Key</option>
            <option value="password">Password</option>
        </select>
    </div>

    @if ($loginType === 'password')
        <div>
            <label class="block font-bold">Root Password</label>
            <input wire:model.live="sshPassword" class="w-full border p-2 rounded" type="password" />
            @error('sshPassword') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
        </div>
    @else
        <div>
            <label class="block font-bold">SSH Private Key</label>
            <textarea wire:model.live="sshKey" class="w-full border p-2 rounded" rows="5"></textarea>
            @error('sshKey') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
        </div>
    @endif

    <div>
        <label class="block font-bold">Protocol</label>
        <select wire:model.live="protocol" class="w-full border p-2 rounded">
            <option value="openvpn">OpenVPN</option>
            <option value="wireguard">WireGuard</option>
        </select>
    </div>

    <button wire:click="submit"
        class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">
        🚀 Deploy VPN Server
    </button>
</div>
