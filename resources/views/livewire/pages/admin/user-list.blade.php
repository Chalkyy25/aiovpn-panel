<!-- Flash Message -->
@if (session()->has('status-message'))
    <div class="mb-4 p-4 bg-green-100 border border-green-300 text-green-800 rounded">
        {{ session('status-message') }}
    </div>
@endif
<!-- 🔎 Filters -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
    <x-input type="text" placeholder="Search by name or email" wire:model.defer="search" class="w-full md:w-1/3" />
    <x-select wire:model="roleFilter" class="w-full md:w-48">
        <option value="">All Roles</option>
        <option value="admin">Admin</option>
        <option value="reseller">Reseller</option>
        <option value="client">Client</option>
    </x-select>
</div>

<!-- 🖥️ Desktop Table -->
<div class="hidden md:block overflow-x-auto">
    <table class="min-w-full table-auto text-sm border">
        <thead class="bg-gray-100 text-left">
            <tr>
                <th class="px-4 py-2">Email</th>
                <th class="px-4 py-2">Active</th>
                <th class="px-4 py-2">Role</th>
                <th class="px-4 py-2">Created By</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $user->email }}</td>
                    <td class="px-4 py-2">
                        <span class="{{ $user->is_active ? 'text-green-600' : 'text-red-600' }}">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-2">{{ $user->role }}</td>
                    <td class="px-4 py-2">{{ $user->creator->name ?? '—' }}</td>
                    <td class="px-4 py-2 space-x-2">
                        <x-button wire:click.prevent="startEdit({{ $user->id }})" size="sm">Edit</x-button>
                        <x-button variant="danger" wire:click.prevent="confirmDelete({{ $user->id }})" size="sm">Delete</x-button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<!-- 📱 Mobile Cards -->
<div class="md:hidden space-y-4">
    @foreach ($users as $user)
        <div class="border rounded p-4 shadow-sm space-y-2">
            <div><strong>Email:</strong> {{ $user->email }}</div>
            <div>
                <strong>Status:</strong>
                <span class="{{ $user->is_active ? 'text-green-600' : 'text-red-600' }}">
                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>
            <div><strong>Role:</strong> {{ $user->role }}</div>
            <div><strong>Created By:</strong> {{ $user->creator->name ?? '—' }}</div>
            <div class="flex gap-2 pt-1">
                <x-button wire:click.prevent="startEdit({{ $user->id }})" size="sm">Edit</x-button>
                <x-button variant="danger" wire:click.prevent="confirmDelete({{ $user->id }})" size="sm">Delete</x-button>
            </div>
        </div>
    @endforeach
</div>
