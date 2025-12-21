<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  <script>
    // Apply theme early (default LIGHT). No flash.
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

<body class="aio-bg min-h-full font-sans antialiased" x-data="panelLayout()" x-init="init()">

<div class="min-h-screen flex text-[var(--aio-ink)]">


    {{-- DESKTOP SIDEBAR --}}
    <aside class="sidebar hidden md:flex md:flex-col border-r border-[var(--aio-border)] bg-[color-mix(in_srgb,var(--aio-card)_92%,transparent)] backdrop-blur-md
                  transition-[width] duration-200"
           :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'"
           aria-label="Main Navigation">

      <div class="h-16 flex items-center justify-between px-3 border-b border-[var(--aio-border)]">
        <div class="flex items-center gap-2 overflow-hidden">
          <x-application-logo type="mark" class="w-8 h-8 shrink-0"/>
          <span x-show="!sidebarCollapsed" class="font-bold truncate">AIO VPN</span>
        </div>

        <button class="hidden md:inline-flex p-2 rounded-md border border-transparent hover:border-[var(--aio-border)]
                       hover:bg-[var(--aio-hover)]"
                type="button"
                @click="toggleCollapse()"
                :aria-expanded="!sidebarCollapsed"
                aria-label="Toggle sidebar">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  :d="sidebarCollapsed ? 'M15 19l-7-7 7-7' : 'M9 5l7 7-7 7'"/>
          </svg>
        </button>
      </div>

      <div class="p-2">
        @include('layouts.partials.sidebar')
      </div>
    </aside>

    {{-- MOBILE DRAWER --}}
    <template x-teleport="body">
      <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-[100]"
           @keydown.escape.window="sidebarOpen=false" x-transition.opacity>

        {{-- overlay --}}
        <div class="absolute inset-0 bg-black/40"
             @click="sidebarOpen=false"
             aria-hidden="true"></div>

        <aside class="drawer absolute left-0 top-0 bottom-0 w-72 bg-[var(--aio-card)] border-r border-[var(--aio-border)]
                      p-3 z-[101] overflow-y-auto"
               x-transition:enter="transform transition ease-out duration-200"
               x-transition:enter-start="-translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transform transition ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="-translate-x-full"
               role="dialog" aria-modal="true">

          <div class="h-14 flex items-center justify-between border-b border-[var(--aio-border)] pb-2 mb-2">
            <span class="font-semibold">AIO VPN</span>

            <button class="p-2 rounded-md border border-transparent hover:border-[var(--aio-border)]
                           hover:bg-[var(--aio-hover)]"
                    type="button"
                    @click="sidebarOpen=false"
                    aria-label="Close">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          @include('layouts.partials.sidebar')
        </aside>
      </div>
    </template>

    {{-- MAIN --}}
    <div class="flex-1 flex flex-col min-w-0">

      <header class="h-16 flex items-center justify-between px-3 md:px-4 sticky top-0 z-50
                     border-b border-[var(--aio-border)] bg-[color-mix(in_srgb,var(--aio-card)_92%,transparent)] backdrop-blur-md">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded-md border border-transparent hover:border-[var(--aio-border)]
                         hover:bg-[var(--aio-hover)]"
                  type="button"
                  @click="sidebarOpen=true"
                  aria-label="Open menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>

          <h1 class="text-lg font-semibold">{{ $heading ?? '' }}</h1>
        </div>

        <div class="flex items-center gap-3">
          @auth
            <a href="{{ route('admin.credits') }}"
               class="aio-pill inline-flex items-center gap-1.5">
              <x-icon name="o-currency-dollar" class="w-4 h-4"/>
              {{ auth()->user()->credits ?? 0 }}
            </a>
          @endauth

          <button class="aio-pill" type="button"
                  @click="
                    const el=document.documentElement;
                    const isDark=el.classList.toggle('dark');
                    localStorage.setItem('theme', isDark ? 'dark' : 'light');
                  ">
            🌓 Theme
          </button>

          <x-user-menu/>
        </div>
      </header>

      <main class="p-3 md:p-4">
        @yield('content')
        {{ $slot ?? '' }}
      </main>

    </div>
  </div>

  @stack('scripts')

  @livewireScripts
  @livewireScriptConfig
  @vite(['resources/js/app.js'])

  <script>
    function panelLayout(){
      return {
        sidebarOpen:false,
        sidebarCollapsed:false,
        init(){
          const saved = localStorage.getItem('aio.sidebarCollapsed');
          if(saved !== null) this.sidebarCollapsed = saved === '1';

          this.$watch('sidebarOpen', v => {
            document.body.classList.toggle('overflow-hidden', v);
          });

          window.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === '\\\\') {
              e.preventDefault();
              this.toggleCollapse();
            }
          });
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