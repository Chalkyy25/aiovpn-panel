<div class="max-w-xl mx-auto p-6 bg-white shadow rounded space-y-5">
    <h2 class="text-xl font-semibold">Create New VPN Client</h2>
{{ dd($server) }}
    @if (session()->has('success'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300">
            {{ session('success') }}
        </div>
    @endif


    <form action="{{ route('admin.servers.users.store', ['vpnServer' => $server->id]) }}" method="POST">
        @csrf
        <!-- Username -->
        <div>
            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
            <input id="username" name="username" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="Enter username" />
            @error('username')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="Enter password" />
            @error('password')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Submit -->
        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Create VPN User
            </button>
        </div>
    </form>
</div>
