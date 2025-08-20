<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  <script>
    try{ if((localStorage.getItem('theme') ?? 'dark')==='dark'){ document.documentElement.classList.add('dark'); } }catch(_){}
  </script>

  @vite(['resources/css/app.css'])
  @livewireStyles
  @stack('styles')
  <style>[x-cloak]{display:none!important}</style>
</head>

<body class="font-sans antialiased min-h-screen" x-data="panelLayout()" x-init="init()">
  <div class="min-h-screen flex">
    {{-- Sidebar (desktop) --}}
    <aside class="hidden md:flex md:flex-col aio-card border-r transition-[width] duration-200"
           :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'">
      <div class="h-16 flex items-center justify-between px-3">
        <div class="flex items-center gap-2 overflow-hidden">
          <x-application-logo type="mark" class="w-8 h-8 shrink-0"/>
          <span class="font-bold truncate" x-show="!sidebarCollapsed">AIO VPN</span>
        </div>
        <button class="hidden md:inline-flex p-2 rounded hover:bg-white/10"
                @click="toggleCollapse()">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  :d="sidebarCollapsed ? 'M15 19l-7-7 7-7' : 'M9 5l7 7-7 7'"/>
          </svg>
        </button>
      </div>

      {{-- your nav links --}}
      @include('layouts.partials.sidebar')
    </aside>

    {{-- Main --}}
    <div class="flex-1 flex flex-col">
      <header class="aio-header h-16 flex items-center justify-between px-3 md:px-4">
        <h1 class="text-lg font-semibold">{{ $heading ?? '' }}</h1>
        <div class="flex items-center gap-3">
          @auth
            <a href="{{ route('admin.credits') }}" class="aio-pill inline-flex items-center gap-1.5">
              <x-icon name="o-currency-dollar" class="w-4 h-4"/> {{ auth()->user()->credits ?? 0 }}
            </a>
          @endauth
          <button class="aio-pill"
                  @click="const d=document.documentElement;const dark=d.classList.toggle('dark');localStorage.setItem('theme',dark?'dark':'light')">
            🌓 Theme
          </button>
          <x-user-menu/>
        </div>
      </header>

      <main class="p-3 md:p-4">
        {{-- Livewire pages (#[Layout('layouts.app')]) --}}
        {{ $slot ?? '' }}

        {{-- Traditional Blade pages that call @section('content') --}}
        @yield('content')
      </main>
    </div>
  </div>

  @vite(['resources/js/app.js'])
  @livewireScripts
  @stack('scripts')

  <script>
    function panelLayout(){
      return {
        sidebarOpen:false, sidebarCollapsed:false,
        init(){ const s=localStorage.getItem('aio.sidebarCollapsed'); if(s!==null) this.sidebarCollapsed = s==='1'; },
        toggleCollapse(){ this.sidebarCollapsed=!this.sidebarCollapsed; localStorage.setItem('aio.sidebarCollapsed', this.sidebarCollapsed?'1':'0'); }
      }
    }
  </script>
</body>
</html>