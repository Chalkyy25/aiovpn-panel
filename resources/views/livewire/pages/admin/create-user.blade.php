<div class="max-w-xl mx-auto p-6 space-y-5">
    <div class="aio-card p-6">
        <h2 class="text-xl font-semibold text-[var(--aio-ink)] mb-6">Create New VPN Client</h2>

        @if (session()->has('success'))
            <div class="p-3 bg-green-900/20 text-green-100 rounded border border-green-700 mb-4">
                {{ session('success') }}
            </div>
        @endif


        <form action="{{ route('admin.servers.users.store', ['vpnServer' => $server->id]) }}" method="POST" class="space-y-4">
            @csrf
            <!-- Username -->
            <div>
                <label for="username" class="block text-sm font-medium text-[var(--aio-sub)] mb-1">Username</label>
                <input id="username" name="username" type="text" class="form-input w-full" placeholder="Enter username" />
                @error('username')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-[var(--aio-sub)] mb-1">Password</label>
                <input id="password" name="password" type="password" class="form-input w-full" placeholder="Enter password" />
                @error('password')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Submit -->
            <div class="flex justify-end pt-2">
                <x-button type="submit" variant="primary">
                    Create VPN User
                </x-button>
            </div>
        </form>
    </div>
</div>
