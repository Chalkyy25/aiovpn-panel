@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto mt-10">
    <div class="aio-card p-6 rounded-lg border border-white/10">
        <h2 class="text-2xl font-bold mb-6 text-[var(--aio-ink)] text-center">Client Login</h2>

        {{-- Errors --}}
        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-500/30 bg-red-500/10 text-red-200 p-3 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('client.login') }}" class="space-y-5" x-data="{ show:false }">
            @csrf

            {{-- Username --}}
            <div>
                <x-label for="username" class="text-[var(--aio-sub)]">Username</x-label>
                <x-input
                    id="username"
                    name="username"
                    type="text"
                    value="{{ old('username') }}"
                    required
                    autofocus
                    class="w-full mt-1"
                    autocomplete="username"
                />
            </div>

            {{-- Password with show/hide (Alpine is optional; remove x-attrs if you don’t want it) --}}
            <div class="relative">
                <x-label for="password" class="text-[var(--aio-sub)]">Password</x-label>
                <x-input
                    id="password"
                    name="password"
                    :type="show ? 'text' : 'password'"
                    required
                    class="w-full mt-1 pr-10"
                    autocomplete="current-password"
                />
                <button type="button"
                        class="absolute inset-y-0 right-0 mt-7 mr-3 text-xs aio-pill bg-white/5 hover:shadow-glow"
                        @click="show = !show">
                    <span x-show="!show">Show</span>
                    <span x-show="show">Hide</span>
                </button>
            </div>

            {{-- Submit --}}
            <div class="pt-2">
                <x-button type="submit" class="w-full aio-pill pill-cya shadow-glow">
                    Login
                </x-button>
            </div>
        </form>
    </div>

    {{-- Optional: footer / help --}}
    <p class="mt-4 text-center text-xs text-[var(--aio-sub)]">
        Having trouble? Contact support.
    </p>
</div>
@endsection