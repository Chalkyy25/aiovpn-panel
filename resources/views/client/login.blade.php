@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto mt-10">
    <div class="aio-card p-6 rounded-lg border border-white/10">
        <h2 class="text-2xl font-bold mb-6 text-[var(--aio-ink)] text-center">Client Login</h2>

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-500/30 bg-red-500/10 text-red-200 p-3 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('client.login') }}" class="space-y-5">
            @csrf

            {{-- Username --}}
            <x-input
                label="Username"
                name="username"
                type="text"
                autocomplete="username"
                value="{{ old('username') }}"
                required
                autofocus
            />

            {{-- Password (toggle is built into x-input) --}}
            <x-input
                label="Password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            />

            <x-button type="submit" class="w-full aio-pill pill-cya shadow-glow">
                Login
            </x-button>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-[var(--aio-sub)]">
        Having trouble? Contact support.
    </p>
</div>
@endsection