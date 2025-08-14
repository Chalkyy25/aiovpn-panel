@props(['class' => ''])

<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ open: false }">
    <button @click="open = !open" class="flex items-center gap-2 p-2 rounded-full hover:bg-gray-100">
        <img src="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()->name ?? 'User') }}&size=32" 
             class="w-8 h-8 rounded-full" alt="User Avatar">
    </button>

    <div x-show="open" @click.away="open = false" x-cloak
         class="absolute right-0 mt-2 w-48 bg-white border rounded-md shadow-lg z-50">
        <a href="{{ route('profile.show') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
            Profile
        </a>
        <a href="{{ route('settings') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
            Settings
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50">
                Log Out
            </button>
        </form>
    </div>
</div>