<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  {{-- Apply dark theme ASAP (no Alpine needed up here) --}}
  <script>
    (function () {
      try {
        const v = localStorage.getItem('theme') ?? 'dark';
        if (v === 'dark') document.documentElement.classList.add('dark');
      } catch (e) {}
    })();
  </script>

  {{-- Styles first --}}
  @vite(['resources/css/app.css'])
  @livewireStyles
  @stack('styles')
  @livewireScripts
  @livewireStyles
  @livewireScriptConfig

  <style>[x-cloak]{display:none!important}</style>
</head>

<body class="font-sans antialiased min-h-screen"
      x-data="panelLayout()" x-init="init()">

  <div class="min-h-screen flex">
    {{-- ===== Left sidebar (desktop: collapsible) ===== --}}
    <aside class="hidden md:flex md:flex-col aio-card border-r transition-[width] duration-200"
           :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'"
           aria-label="Main Navigation">
      <div class="h-16 flex items-center justify-between px-3">
        <div class="flex items-center gap-2 overflow-hidden">
          <x-application-logo type="mark" class="w-8 h-8 shrink-0"/>
          <span class="font-bold truncate" x-show="!sidebarCollapsed">AIO VPN</span>
        </div>
        <button class="hidden md:inline-flex p-2 rounded hover:bg-white/10"
                @click="toggleCollapse()" :aria-expanded="!sidebarCollapsed"
                :title="sidebarCollapsed ? 'Expand' : 'Collapse'">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  :d="sidebarCollapsed ? 'M15 19l-7-7 7-7' : 'M9 5l7 7-7 7'"/>
          </svg>
        </button>
      </div>

      @include('layouts.partials.sidebar')
    </aside>

    {{-- ===== Mobile drawer ===== --}}
    <div class="md:hidden" x-show="sidebarOpen" x-cloak>
      <div class="fixed inset-0 z-100">
        <div class="absolute inset-0 bg-black/30" @click="sidebarOpen=false"></div>
        <aside class="absolute left-0 top-0 bottom-0 w-72 aio-card border-r p-3">
          <div class="h-14 flex items-center justify-between">
            <span class="font-semibold">AIO VPN</span>
            <button class="p-2 rounded hover:bg-white/10" @click="sidebarOpen=false" aria-label="Close">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          @include('layouts.partials.sidebar')
        </aside>
      </div>
    </div>

    {{-- ===== Main ===== --}}
    <div class="flex-1 flex flex-col">
      <header class="aio-header h-16 flex items-center justify-between px-3 md:px-4">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded hover:bg-white/10"
                  @click="sidebarOpen=true" aria-label="Open menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>
          <h1 class="text-lg font-semibold">{{ $heading ?? '' }}</h1>
        </div>

        <div class="flex items-center gap-3">
          @auth
            <a href="{{ route('admin.credits') }}" class="aio-pill inline-flex items-center gap-1.5">
              <x-icon name="o-currency-dollar" class="w-4 h-4"/>
              {{ auth()->user()->credits ?? 0 }}
            </a>
          @endauth

          {{-- Theme toggle --}}
          <button class="aio-pill"
                  @click="
                    const el = document.documentElement;
                    const dark = el.classList.toggle('dark');
                    localStorage.setItem('theme', dark ? 'dark' : 'light');
                  ">
            🌓 Theme
          </button>

          <x-user-menu/>
        </div>
      </header>

      <main class="p-3 md:p-4">
        {{ $slot ?? '' }}
        @yield('content')
      </main>
    </div>
  </div>

  {{-- JS LAST, in this order --}}
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
          window.addEventListener('keydown',(e)=>{
            const meta = e.ctrlKey || e.metaKey;
            if(meta && e.key==='\\'){ e.preventDefault(); this.toggleCollapse(); }
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