<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name','AIO VPN') }}</title>

  <script>
    (function(){
      try{ if((localStorage.getItem('theme') ?? 'dark') === 'dark') document.documentElement.classList.add('dark'); }catch(e){}
    })();
  </script>

  {{-- One Vite entry (it will include your CSS and JS) --}}
  @vite(['resources/js/app.js'])

  {{-- Livewire styles ONCE --}}
  @livewireStyles

  @stack('styles')

  <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased min-h-screen" x-data="panelLayout()" x-init="init()">

  {{-- ... your sidebar/header/content exactly as you have it ... --}}

  {{-- Livewire scripts ONCE + stacks --}}
  @livewireScripts
  @stack('scripts')

  <script>
    function panelLayout(){
      return {
        sidebarOpen:false, sidebarCollapsed:false,
        init(){
          const saved = localStorage.getItem('aio.sidebarCollapsed');
          if(saved !== null) this.sidebarCollapsed = saved === '1';
          window.addEventListener('keydown',(e)=>{
            if((e.ctrlKey||e.metaKey) && e.key==='\\'){ e.preventDefault(); this.toggleCollapse(); }
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