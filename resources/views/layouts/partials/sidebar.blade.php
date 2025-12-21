<nav class="px-2 pb-4 space-y-3 text-sm" aria-label="Sidebar">

  {{-- MAIN --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      Main
    </div>

    <x-nav-link href="{{ route('admin.dashboard') }}"
                :active="request()->routeIs('admin.dashboard')"
                icon="o-home">
      Dashboard
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-dashboard') }}"
                :active="request()->routeIs('admin.vpn-dashboard')"
                icon="o-chart-bar">
      VPN Monitor
    </x-nav-link>

    <x-nav-link href="{{ route('admin.servers.index') }}"
                :active="request()->routeIs('admin.servers.*')"
                icon="o-server">
      Servers
    </x-nav-link>
  </div>

  <hr class="mx-2 border-[var(--aio-border)]">

  {{-- USERS --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      Users
    </div>

    <x-nav-link href="{{ route('admin.resellers.create') }}"
                :active="request()->routeIs('admin.resellers.create')"
                icon="o-plus">
      Add User
    </x-nav-link>

    <x-nav-link href="{{ route('admin.resellers.index') }}"
                :active="request()->routeIs('admin.resellers.index')"
                icon="o-user-group">
      Manage Users
    </x-nav-link>
  </div>

  <hr class="mx-2 border-[var(--aio-border)]">

  {{-- LINES --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      Lines
    </div>

    <x-nav-link href="{{ route('admin.vpn-users.create') }}"
                :active="request()->routeIs('admin.vpn-users.create')"
                icon="o-plus-circle">
      Add Line
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-users.trial') }}"
                :active="request()->routeIs('admin.vpn-users.trial')"
                icon="o-clock">
      Generate Trial
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-users.index') }}"
                :active="request()->routeIs('admin.vpn-users.index')"
                icon="o-list-bullet">
      Manage Lines
    </x-nav-link>
  </div>

  <hr class="mx-2 border-[var(--aio-border)]">

  {{-- APP --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      App
    </div>

    <x-nav-link href="{{ route('admin.app-builds.index') }}"
                :active="request()->routeIs('admin.app-builds.*')"
                icon="o-arrow-up-tray">
      App Builds
    </x-nav-link>
  </div>

  <hr class="mx-2 border-[var(--aio-border)]">

  {{-- SYSTEM --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      System
    </div>

    <x-nav-link href="{{ route('admin.settings') }}"
                :active="request()->routeIs('admin.settings')"
                icon="o-cog-6-tooth">
      Settings
    </x-nav-link>
  </div>

  <hr class="mx-2 border-[var(--aio-border)]">

  {{-- PACKAGES --}}
  <div class="space-y-1">
    <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">
      Packages
    </div>

    <x-nav-link href="{{ route('admin.packages.index') }}"
                :active="request()->routeIs('admin.packages.index') || request()->routeIs('admin.packages.edit')"
                icon="o-archive-box">
      Manage Packages
    </x-nav-link>

    <x-nav-link href="{{ route('admin.packages.create') }}"
                :active="request()->routeIs('admin.packages.create')"
                icon="o-plus-circle">
      New Package
    </x-nav-link>
  </div>

  {{-- BILLING --}}
  @auth
    @php
      $u = auth()->user();
      $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
      $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
      $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
    @endphp

    @if ($isAdmin || $isReseller)
      <hr class="mx-2 border-[var(--aio-border)]">

      <div class="space-y-1">
        <div class="px-3 pt-2 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
             x-show="!$root.sidebarCollapsed">
          Billing
        </div>

        <x-nav-link href="{{ $creditsUrl }}"
                    icon="o-banknotes">
          Credits: {{ $u->credits }}
        </x-nav-link>
      </div>
    @endif
  @endauth

</nav>