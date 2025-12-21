@props(['class' => ''])

@php
    use Illuminate\Support\Facades\Route;

    /** @var \App\Models\User|null $u */
    $u = auth()->user();
    $isLoggedIn = (bool) $u;

    $isAdmin    = $isLoggedIn && (method_exists($u,'isAdmin')    ? $u->isAdmin()    : ($u->role ?? null) === 'admin');
    $isReseller = $isLoggedIn && (method_exists($u,'isReseller') ? $u->isReseller() : ($u->role ?? null) === 'reseller');

    $creditsUrl = $isAdmin
        ? (Route::has('admin.credits')    ? route('admin.credits')    : '#')
        : ($isReseller
            ? (Route::has('reseller.credits') ? route('reseller.credits') : '#')
            : '#');

    $profileUrl  = Route::has('profile.show')
        ? route('profile.show')
        : (Route::has('profile.edit') ? route('profile.edit') : null);

    $settingsUrl = Route::has('admin.settings')
        ? route('admin.settings')
        : (Route::has('settings') ? route('settings') : null);

    $logoutUrl = Route::has('logout') ? route('logout') : url('/logout');
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ open: false }">
    {{-- Avatar button --}}
    <button @click="open = !open"
            class="flex items-center gap-2 p-1 rounded-full hover:bg-[var(--aio-hover)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-accent)]">
        <img src="https://ui-avatars.com/api/?name={{ urlencode($u->name ?? 'Guest') }}&size=64&background=0D8ABC&color=fff"
             class="w-8 h-8 rounded-full" alt="User Avatar">
    </button>

    {{-- Dropdown --}}
    <div x-show="open" @click.outside="open = false" x-cloak
         class="absolute right-0 mt-2 w-56 bg-[var(--aio-card)] border border-[var(--aio-border)] rounded-md shadow-lg z-50">

        {{-- Header --}}
        <div class="px-4 py-3 border-b border-[var(--aio-border)]">
            <div class="font-semibold text-[var(--aio-ink)]">{{ $u->name ?? 'Guest' }}</div>
            <div class="text-sm text-[var(--aio-sub)] capitalize">{{ $u->role ?? '' }}</div>

            @if($isAdmin || $isReseller)
                <a href="{{ $creditsUrl }}"
                   class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:underline">
                    💰 Credits: {{ $u->credits ?? 0 }}
                </a>
            @endif
        </div>

        {{-- Menu links --}}
        <div class="py-1">
            @if($isLoggedIn)
                @if($profileUrl)
                    <a href="{{ $profileUrl }}" class="block px-4 py-2 text-sm text-[var(--aio-ink)] hover:bg-[var(--aio-hover)]">
                        Profile
                    </a>
                @endif

                @if($settingsUrl)
                    <a href="{{ $settingsUrl }}" class="block px-4 py-2 text-sm text-[var(--aio-ink)] hover:bg-[var(--aio-hover)]">
                        Settings
                    </a>
                @endif
            @else
                @if(Route::has('login'))
                    <a href="{{ route('login') }}" class="block px-4 py-2 text-sm text-[var(--aio-ink)] hover:bg-[var(--aio-hover)]">
                        Log in
                    </a>
                @endif
                @if(Route::has('register'))
                    <a href="{{ route('register') }}" class="block px-4 py-2 text-sm text-[var(--aio-ink)] hover:bg-[var(--aio-hover)]">
                        Register
                    </a>
                @endif
            @endif
        </div>

        {{-- Logout / only when logged in --}}
        @if($isLoggedIn)
            <div class="border-t border-[var(--aio-border)]">
                <form method="POST" action="{{ $logoutUrl }}">
                    @csrf
                    <button type="submit"
                            class="block w-full text-left px-4 py-2 text-sm text-[var(--aio-danger)] hover:bg-[var(--aio-hover)]">
                        Log Out
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>