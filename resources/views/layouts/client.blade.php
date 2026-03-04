<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  <script>
    try {
      const saved = localStorage.getItem('theme') ?? 'light';
      if (saved === 'dark') document.documentElement.classList.add('dark');
      else document.documentElement.classList.remove('dark');
    } catch (e) {}
  </script>

  @vite(['resources/css/app.css'])
  @livewireStyles
  @stack('styles')
  <style>[x-cloak]{display:none!important}</style>
</head>

<body class="aio-bg min-h-full font-sans antialiased">
  <div class="min-h-screen flex flex-col text-[var(--aio-ink)]">

    <header class="h-16 flex items-center justify-between px-3 md:px-4 sticky top-0 z-50
                   border-b border-[var(--aio-border)] bg-[color-mix(in_srgb,var(--aio-card)_92%,transparent)] backdrop-blur-md">
      <div class="flex items-center gap-2">
        <x-application-logo type="mark" class="w-8 h-8 shrink-0"/>
        <span class="font-bold truncate">AIO VPN</span>
      </div>

      <div class="flex items-center gap-3">
        <button class="aio-pill" type="button"
                onclick="
                  const el=document.documentElement;
                  const isDark=el.classList.toggle('dark');
                  localStorage.setItem('theme', isDark ? 'dark' : 'light');
                ">
          🌓 Theme
        </button>

        @if(auth('client')->check())
          <form method="POST" action="{{ route('client.logout') }}">
            @csrf
            <x-button type="submit" class="aio-pill">Logout</x-button>
          </form>
        @endif
      </div>
    </header>

    <main class="p-3 md:p-4 flex-1">
      @yield('content')
    </main>

  </div>

  @stack('scripts')
  @livewireScripts
  @livewireScriptConfig
  @vite(['resources/js/app.js'])
</body>
</html>