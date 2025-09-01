@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">Manage Packages</h1>
        <a href="{{ route('admin.packages.create') }}" class="btn">+ New Package</a>
    </div>

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
                    <th class="px-4 py-2 text-left">Credits</th>
                    <th class="px-4 py-2 text-left">Max Devices</th>
                    <th class="px-4 py-2 text-left">Duration</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Created</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packages as $package)
                    <tr>
                        {{-- Package name --}}
                        <td class="px-4 py-2 font-medium">{{ $package->name }}</td>

                        {{-- Credits --}}
                        <td class="px-4 py-2">{{ $package->price_credits }}</td>

                        {{-- Devices --}}
                        <td class="px-4 py-2">
                            {{ $package->max_connections === 0 ? 'Unlimited' : $package->max_connections }}
                        </td>

                        {{-- Duration --}}
                        <td class="px-4 py-2">
                            {{ $package->duration_months }} mo
                        </td>

                        {{-- Status --}}
                        <td class="px-4 py-2">
                            @if($package->is_active)
                                <span class="aio-pill pill-neon text-xs">Active</span>
                            @else
                                <span class="aio-pill text-xs">Inactive</span>
                            @endif
                            @if($package->is_featured)
                                <span class="aio-pill pill-mag text-xs">Featured</span>
                            @endif
                        </td>

                        {{-- Created --}}
                        <td class="px-4 py-2">{{ $package->created_at->diffForHumans() }}</td>

                        {{-- Actions --}}
                        <td class="px-4 py-2 text-right flex justify-end gap-2">
                            <a href="{{ route('admin.packages.edit', $package) }}" 
                               class="btn-secondary text-xs">Edit</a>
                            <form method="POST" action="{{ route('admin.packages.destroy', $package) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn-danger text-xs"
                                        onclick="return confirm('Delete this package?')">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-4 text-center text-[var(--aio-sub)]">
                            No packages yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $packages->links() }}
</div>
@endsection