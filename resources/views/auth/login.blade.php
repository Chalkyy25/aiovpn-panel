@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto mt-10">
    <div class="aio-card p-6 rounded-lg border border-white/10">
        <h2 class="text-2xl font-bold mb-6 text-[var(--aio-ink)] text-center">Admin / Reseller Login</h2>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-white/10 bg-white/5 text-[var(--aio-ink)] p-3 text-sm">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-500/30 bg-red-500/10 text-red-200 p-3 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- IMPORTANT: admin/reseller -> web guard --}}
        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <x-input
                label="Email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            />

            <x-input
                label="Password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            />

            <label class="inline-flex items-center text-sm text-[var(--aio-sub)]">
                <input id="remember" name="remember" type="checkbox" class="rounded border-gray-600 bg-white/5">
                <span class="ml-2">Remember me</span>
            </label>

            <div class="flex items-center justify-between pt-2">
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs underline text-[var(--aio-sub)] hover:text-[var(--aio-ink)]">
                        Forgot your password?
                    </a>
                @endif

                <x-button type="submit" class="aio-pill pill-cya shadow-glow">
                    Log in
                </x-button>
            </div>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-[var(--aio-sub)]">
        Client? <a href="{{ route('client.login.form') }}" class="underline">Use client login</a>.
    </p>
</div>
@endsection