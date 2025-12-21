<div>
    <!-- Flash Message -->
    @if (session()->has('status-message'))
        <div class="mb-4 p-4 bg-green-900/20 border border-green-700 text-green-100 rounded">
            {{ session('status-message') }}
        </div>
    @endif

    <!-- Edit Form (shows when editingUser is set) -->
    @if ($editingUser)
        <div class="mb-6 aio-card p-4">
            <h3 class="font-semibold mb-4 text-[var(--aio-ink)]">Edit User: {{ $editingUser->email }}</h3>
            <x-input label="Name" wire:model="editName" class="mb-3" />
            <x-input label="Email" wire:model="editEmail" class="mb-3" />
            <x-select label="Role" wire:model="editRole" :options="['admin' => 'Admin', 'reseller' => 'Reseller', 'client' => 'Client']" class="mb-4" />

            <div class="flex gap-2">
                <x-button wire:click="updateUser" variant="primary">Save</x-button>
                <x-button variant="secondary" wire:click="cancelEdit">Cancel</x-button>
            </div>
        </div>
    @endif

    <!-- 🔎 Filters -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <x-input type="text" placeholder="Search by name or email" wire:model.live="search" class="w-full md:w-1/3" />
        <x-select wire:model="roleFilter" class="w-full md:w-48">
            <option value="">All Roles</option>
            <option value="admin">Admin</option>
            <option value="reseller">Reseller</option>
            <option value="client">Client</option>
        </x-select>
    </div>

    <!-- 🖥️ Desktop Table -->
    <div class="hidden md:block overflow-x-auto">
        <div class="aio-card overflow-hidden">
            <table class="aio-table min-w-full">
                <thead>
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
                    <tr>
                        <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $user->email }}</td>
                        <td class="px-4 py-2">
                            @if($user->is_active)
                                <span class="aio-pill pill-success">Active</span>
                            @else
                                <span class="aio-pill pill-danger">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $user->role }}</td>
                        <td class="px-4 py-2 text-[var(--aio-sub)]">{{ $user->creator->name ?? '—' }}</td>
                        <td class="px-4 py-2 space-x-2">
                            <x-button wire:click.prevent="startEdit({{ $user->id }})" size="sm" variant="secondary">Edit</x-button>
                            <x-button variant="danger" wire:click.prevent="confirmDelete({{ $user->id }})" size="sm">Delete</x-button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <!-- 📱 Mobile Cards -->
    <div class="md:hidden space-y-4">
        @foreach ($users as $user)
            <div class="aio-card p-4 space-y-2">
                <div><strong class="text-[var(--aio-ink)]">Email:</strong> <span class="text-[var(--aio-ink)]">{{ $user->email }}</span></div>
                <div>
                    <strong class="text-[var(--aio-ink)]">Status:</strong>
                    @if($user->is_active)
                        <span class="aio-pill pill-success">Active</span>
                    @else
                        <span class="aio-pill pill-danger">Inactive</span>
                    @endif
                </div>
                <div><strong class="text-[var(--aio-ink)]">Role:</strong> <span class="text-[var(--aio-ink)]">{{ $user->role }}</span></div>
                <div><strong class="text-[var(--aio-ink)]">Created By:</strong> <span class="text-[var(--aio-sub)]">{{ $user->creator->name ?? '—' }}</span></div>
                <div class="flex gap-2 pt-1">
                    <x-button wire:click.prevent="startEdit({{ $user->id }})" size="sm" variant="secondary">Edit</x-button>
                    <x-button variant="danger" wire:click.prevent="confirmDelete({{ $user->id }})" size="sm">Delete</x-button>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Confirm Delete Modal -->
    @if ($confirmingDeleteId)
        <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-40 z-50">
            <div class="aio-card p-6 w-full max-w-sm">
                <h2 class="text-lg font-semibold mb-4 text-[var(--aio-ink)]">Delete User</h2>
                <p class="mb-4 text-[var(--aio-sub)]">Are you sure you want to delete this user?</p>
                <div class="flex gap-2 justify-end">
                    <x-button wire:click="deleteUser" variant="danger">Yes, Delete</x-button>
                    <x-button wire:click="$set('confirmingDeleteId', null)" variant="secondary">Cancel</x-button>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
    <script>
        document.addEventListener('livewire:init', function () {
            Livewire.on('userUpdated', () => {
                // Optionally, you can add any additional JS logic here after a user is updated
            });

            Livewire.on('userDeleted', () => {
                // Optionally, you can add any additional JS logic here after a user is deleted
            });
        });
    </script>
@endpush
