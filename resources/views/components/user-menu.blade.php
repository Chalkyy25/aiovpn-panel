@props(['class' => ''])

@php
    use Illuminate\Support\Facades\Route;

    $u = auth()->user();
    $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
    $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
    $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');

    // Route fallbacks
    $profileUrl  = Route::has('profile.show')
                    ? route('profile.show')
                    : (Route::has('profile.edit') ? route('profile.edit') : null);

    $settingsUrl = Route::has('admin.settings')
                    ? route('admin.settings')
                    : (Route::has('settings') ? route('settings') : null);

    $logoutUrl   = Route::has('logout') ? route('logout') : url('/logout');
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ open: false }">
    {{-- Avatar button --}}
    <button @click="open = !open"
            class="flex items-center gap-2 p-1 rounded-full hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <img src="https://ui-avatars.com/api/?name={{ urlencode($u->name ?? 'User') }}&size=64&background=0D8ABC&color=fff"
             class="w-8 h-8 rounded-full" alt="User Avatar">
    </button>

    {{-- Dropdown --}}
    <div x-show="open" @click.away="open = false" x-cloak
         class="absolute right-0 mt-2 w-56 bg-white border border-gray-200 rounded-md shadow-lg z-50">

        {{-- Header --}}
        <div class="px-4 py-3 border-b border-gray-100">
            <div class="font-semibold text-gray-900">{{ $u->name ?? 'User' }}</div>
            <div class="text-sm text-gray-500 capitalize">{{ $u->role ?? '' }}</div>

            @if ($isAdmin || $isReseller)
                <a href="{{ $creditsUrl }}"
                   class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:underline">
                    💰 Credits: {{ $u->credits }}
                </a>
            @endif
        </div>

        {{-- Menu links (render only if route exists) --}}
        <div class="py-1">
            @if($profileUrl)
                <a href="{{ $profileUrl }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    Profile
                </a>
            @endif

            @if($settingsUrl)
                <a href="{{ $settingsUrl }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    Settings
                </a>
            @endif
        </div>

        {{-- Logout --}}
        <div class="border-t border-gray-100">
            <form method="POST" action="{{ $logoutUrl }}">
                @csrf
                <button type="submit"
                        class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50">
                    Log Out
                </button>
            </form>
        </div>
    </div>
</div>