<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'AIO VPN') }}</title>

    {{-- Apply dark theme ASAP (no Alpine needed) --}}
    <script>
      try {
        (localStorage.getItem('theme') ?? 'dark') === 'dark'
          && document.documentElement.classList.add('dark');
      } catch (e) {}
    </script>

    {{-- Styles first --}}
    @vite(['resources/css/app.css'])
    @livewireStyles
    @stack('styles')

    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased min-h-screen"
      x-data="panelLayout()" x-init="init()">

  {{-- ===== YOUR LAYOUT (sidebar/header/main) GOES HERE ===== --}}
  {{-- Keep your existing markup; only the asset ordering changed. --}}

  {{-- JS last – load once --}}
  @vite(['resources/js/app.js'])
  @livewireScripts
  @livewireScriptConfig
  @stack('scripts')

  <script>
    function panelLayout () {
      return {
        sidebarOpen: false,
        sidebarCollapsed: false,
        init() {
          const saved = localStorage.getItem('aio.sidebarCollapsed');
          if (saved !== null) this.sidebarCollapsed = saved === '1';

          // Handy: toggle sidebar with Ctrl/⌘ + \
          window.addEventListener('keydown', (e) => {
            const meta = e.ctrlKey || e.metaKey;
            if (meta && e.key === '\\') {
              e.preventDefault();
              this.toggleCollapse();
            }
          });
        },
        toggleCollapse() {
          this.sidebarCollapsed = !this.sidebarCollapsed;
          localStorage.setItem('aio.sidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
        }
      }
    }
  </script>
</body>
</html>