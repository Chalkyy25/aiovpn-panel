<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'AIO VPN') }}</title>

    <script>
      try {
        (localStorage.getItem('theme') ?? 'dark') === 'dark'
          && document.documentElement.classList.add('dark');
      } catch (_) {}
    </script>

    @vite(['resources/css/app.css'])
    @livewireStyles
    @stack('styles')
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased min-h-screen" x-data="panelLayout()" x-init="init()">

  {{-- ===== Top‑level shell (keep simple first to prove content renders) ===== --}}
  <header class="aio-header h-14 flex items-center px-4">
    <h1 class="text-lg font-semibold">{{ $heading ?? 'AIO VPN' }}</h1>
  </header>

  <main class="p-4">
      {{-- Livewire component slot (when using #[Layout('layouts.app')]) --}}
      {{ $slot ?? '' }}

      {{-- Blade section content (when using @extends/@section) --}}
      @yield('content')
  </main>

  @vite(['resources/js/app.js'])
  @livewireScripts
  @livewireScriptConfig
  @stack('scripts')

  <script>
    function panelLayout(){
      return {
        sidebarOpen:false, sidebarCollapsed:false,
        init(){
          const saved = localStorage.getItem('aio.sidebarCollapsed');
          if(saved !== null) this.sidebarCollapsed = saved === '1';
        },
        toggleCollapse(){
          this.sidebarCollapsed = !this.sidebarCollapsed;
          localStorage.setItem('aio.sidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
        }
      }
    }
  </script>
</body>
</html>