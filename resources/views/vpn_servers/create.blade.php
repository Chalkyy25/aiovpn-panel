<h2 class="text-xl font-bold mb-4">Add VPN Server</h2>

<form action="{{ route('vpn_servers.store') }}" method="POST" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-bold">Name</label>
        <input type="text" name="name" class="w-full p-2 rounded" required>
    </div>
    <div>
        <label class="block text-sm font-bold">IP Address</label>
        <input type="text" name="ip" class="w-full p-2 rounded" required>
    </div>
    <div>
        <label class="block text-sm font-bold">Protocol</label>
        <select name="protocol" class="w-full p-2 rounded">
            <option value="openvpn">OpenVPN</option>
            <option value="wireguard">WireGuard</option>
        </select>
    </div>
    <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded">Deploy</button>
</form>
