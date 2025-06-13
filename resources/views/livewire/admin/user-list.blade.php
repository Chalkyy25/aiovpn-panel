<div class="flex justify-end mb-4">
    <button
        wire:click="$set('showCreateUser', true)"
        class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
    >
        + Add User
    </button>
</div>
<livewire:admin.create-user wire:key="create-user-modal" />

<div class="max-w-6xl mx-auto p-6 bg-white shadow rounded space-y-5">
    <h2 class="text-xl font-semibold mb-4">User List</h2>

    @if (session()->has('message'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300 mb-4">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4">
        <span class="font-semibold">Total Users:</span>
        <span class="px-2 py-1 rounded bg-gray-200 text-gray-700">{{ $users->count() }}</span>
</div>

    <table class="min-w-full bg-white rounded shadow">
        <thead>
            <tr>
                <th class="py-2 px-3 border-b">ID</th>
                <th class="py-2 px-3 border-b">Name</th>
                <th class="py-2 px-3 border-b">Email</th>
                <th class="py-2 px-3 border-b">Role</th>
                <th class="py-2 px-3 border-b">Created</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="py-2 px-3 border-b">{{ $user->id }}</td>
                    <td class="py-2 px-3 border-b">{{ $user->name }}</td>
                    <td class="py-2 px-3 border-b">{{ $user->email }}</td>
                    <td class="py-2 px-3 border-b">{{ $user->role ?? '-' }}</td>
                    <td class="py-2 px-3 border-b">{{ $user->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-4 text-center text-gray-500">No users found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@if ($showCreateUser)
    <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-40 z-50">
        <div class="bg-white p-6 rounded shadow-lg max-w-md w-full">
            <livewire:admin.create-user wire:key="create-user-modal" />
            <button
                type="button"
                wire:click="$set('showCreateUser', false)"
                class="mt-4 px-4 py-2 rounded border"
            >Cancel</button>
            @if (session()->has('message'))
    <div class="bg-green-100 text-green-800 p-2 rounded mb-2">
        {{ session('message') }}
    </div>
@endif

        </div>
    </div>
@endif


</div>
