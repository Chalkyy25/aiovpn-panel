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
    {{-- Edit --}}
    <a href="{{ route('admin.packages.edit', $package) }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium
              bg-[var(--aio-pup)] text-white hover:brightness-110 transition">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M16.862 3.487a2.25 2.25 0 013.182 3.182l-9.193 9.193-3.182 1.06 1.06-3.182 9.133-9.253z" />
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M19.5 21h-15A1.5 1.5 0 013 19.5v-15A1.5 1.5 0 014.5 3h7.379a1.5 1.5 0 011.061.439l6.621 6.621a1.5 1.5 0 01.439 1.061V19.5A1.5 1.5 0 0119.5 21z" />
        </svg>
        Edit
    </a>

    {{-- Delete --}}
    <form method="POST" action="{{ route('admin.packages.destroy', $package) }}">
        @csrf
        @method('DELETE')
        <button
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium
                   bg-red-600/90 text-white hover:bg-red-700 transition"
            onclick="return confirm('Delete this package?')">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-9 0h10" />
            </svg>
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