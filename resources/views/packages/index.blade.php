@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">Manage Packages</h1>
        <a href="{{ route('admin.packages.create') }}" class="btn">+ New Package</a>
    </div>

    {{-- Success flash --}}
    @if(session('success'))
        <div class="p-3 bg-green-600/20 text-green-400 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-x-auto aio-section">
        <table class="min-w-full table-dark">
            <thead>
                <tr class="text-[var(--aio-sub)] uppercase text-xs">
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">Description</th>
                    <th class="px-4 py-2 text-left">Credits</th>
                    <th class="px-4 py-2 text-left">Max Devices</th>
                    <th class="px-4 py-2 text-left">Duration</th>
                    <th class="px-4 py-2 text-left">Featured</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Created</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packages as $package)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $package->name }}</td>
                        <td class="px-4 py-2 text-sm text-[var(--aio-sub)] truncate max-w-[200px]">
                            {{ $package->description ?? '—' }}
                        </td>
                        <td class="px-4 py-2">{{ $package->price_credits }}</td>
                        <td class="px-4 py-2">
                            {{ $package->max_connections === 0 ? 'Unlimited' : $package->max_connections }}
                        </td>
                        <td class="px-4 py-2">{{ $package->duration_months }} mo</td>
                        <td class="px-4 py-2">
                            <span class="{{ $package->is_featured ? 'text-green-400' : 'text-[var(--aio-sub)]' }}">
                                {{ $package->is_featured ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <span class="{{ $package->is_active ? 'text-green-400' : 'text-red-400' }}">
                                {{ $package->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-[var(--aio-sub)]">
                            {{ $package->created_at->diffForHumans() }}
                        </td>
                        <td class="px-4 py-2 text-right flex justify-end gap-2">
                            <a href="{{ route('admin.packages.edit', $package) }}" class="btn-secondary text-xs">Edit</a>
                            <form method="POST" action="{{ route('admin.packages.destroy', $package) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn-danger text-xs" onclick="return confirm('Delete this package?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-4 text-center text-[var(--aio-sub)]">
                            No packages yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $packages->links() }}</div>
</div>
@endsection