<div class="max-w-xl mx-auto p-6 bg-white shadow rounded space-y-5">
    <h2 class="text-xl font-semibold">Create New User</h2>

    @if (session()->has('message'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300">
            {{ session('message') }}
        </div>
    @endif

    <form wire:submit.prevent="save" class="space-y-4">
        <!-- Name -->
        <div>
            <x-label for="name" value="Name" />
            <x-input id="name" type="text" wire:model.defer="name" class="w-full"
                placeholder="Enter name" />
            @error('name') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <!-- Email -->
        <div>
            <x-label for="email" value="Email" />
            <x-input id="email" type="email" wire:model.defer="email" class="w-full"
                placeholder="Enter email" />
            @error('email') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <!-- Password -->
        <div>
            <x-label for="password" value="Password" />
            <x-input id="password" type="password" wire:model.defer="password" class="w-full"
                placeholder="Enter password" />
            @error('password') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <!-- Role -->
        <div>
            <x-label for="role" value="Role" />
            <select id="role" wire:model.defer="role" class="w-full">
                <option value="">-- Select Role --</option>
                <option value="admin">Admin</option>
                <option value="reseller">Reseller</option>
                <option value="client">Client</option>
            </select>
            @error('role') <p class="text-red-500 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <!-- Submit -->
        <div class="flex justify-end">
            <x-button type="submit" class="px-5">Create User</x-button>
        </div>
    </form>
</div>
