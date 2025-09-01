<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  {{-- Force dark theme ASAP (no Alpine needed) --}}
  <script>
    (function () {
      try {
        const v = localStorage.getItem('theme') ?? 'dark';
        if (v === 'dark') document.documentElement.classList.add('dark');
      } catch (e) {}
    })();
  </script>

  {{-- Styles FIRST --}}
  @vite(['resources/css/app.css'])
  @livewireStyles
  @stack('styles')

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
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  :d="sidebarCollapsed ? 'M15 19l-7-7 7-7' : 'M9 5l7 7-7 7'"/>
          </svg>
          <span class="sr-only">Toggle sidebar</span>
        </button>
      </div>

      @include('layouts.partials.sidebar')
    </aside>

    {{-- ===== Mobile drawer (fixed overlay + blurred background) ===== --}}
<div
  x-show="sidebarOpen"
  x-cloak
  class="fixed inset-0 z-[100] md:hidden"
  x-transition.opacity
>
  {{-- BACKDROP (click to close) --}}
  <div
    class="absolute inset-0 bg-gray-900/60 backdrop-blur-md"
    style="-webkit-backdrop-filter: blur(8px);"  {{-- iOS Safari --}}
    @click="sidebarOpen = false"
    aria-hidden="true"
  ></div>

  {{-- PANEL (stays sharp, sits above the blur) --}}
  <aside
    class="absolute left-0 top-0 bottom-0 z-[110] w-72 p-3 aio-card border-r
           transform transition-transform duration-200 will-change-transform
           translate-x-0"
    x-transition:enter="ease-out duration-200"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="ease-in duration-150"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    role="dialog" aria-modal="true"
  >
    <div class="h-14 flex items-center justify-between">
      <span class="font-semibold">AIO VPN</span>
      <button class="p-2 rounded hover:bg-white/10"
              @click="sidebarOpen=false" aria-label="Close">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    {{-- Same nav everywhere --}}
    @include('layouts.partials.sidebar')
  </aside>
</div>

    {{-- ===== Main ===== --}}
    <div class="flex-1 flex flex-col">
      <header class="aio-header h-16 flex items-center justify-between px-3 md:px-4">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded hover:bg-white/10"
                  @click="sidebarOpen=true" aria-label="Open menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
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

  {{-- JS LAST — your app JS first, then Livewire scripts --}}
  @vite(['resources/js/app.js'])
  @stack('scripts')

  {{-- Livewire v3 scripts MUST be after your app.js --}}
  @livewireScripts
  @livewireScriptConfig

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
<script>
document.addEventListener("livewire:navigated", () => {
    const root = document.querySelector('[wire\\:id]');
    if (root) {
        const comp = Livewire.find(root.getAttribute('wire:id'));
        window.$wire = new Proxy({}, {
            get(_, method) {
                return (...args) => comp.call(method, ...args);
            }
        });
        console.log("✅ $wire proxy attached to", comp);
    } else {
        console.warn("⚠️ No Livewire root component found for $wire proxy");
    }
});
</script>
</body>
</html>