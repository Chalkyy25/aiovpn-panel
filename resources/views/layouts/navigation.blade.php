{{-- Vertical sidebar navigation --}}
<nav class="px-2 pb-4 space-y-1 text-sm text-[var(--aio-ink)]" aria-label="Sidebar">

  {{-- Optional brand label (visible when not collapsed) --}}
  <div class="px-3 py-1.5 font-semibold" x-show="!$root.sidebarCollapsed">AIO VPN</div>

  {{-- MAIN --}}
  <x-nav-link href="{{ route('admin.dashboard') }}"
              :active="request()->routeIs('admin.dashboard')"
              icon="o-home"
              variant="neon">
    Dashboard
  </x-nav-link>

  <x-nav-link href="{{ route('admin.vpn-dashboard') }}"
              :active="request()->routeIs('admin.vpn-dashboard')"
              icon="o-chart-bar"
              variant="cya">
    VPN Monitor
  </x-nav-link>

  {{-- Servers under Monitor --}}
  <x-nav-link href="{{ route('admin.servers.index') }}"
              :active="request()->routeIs('admin.servers.*')"
              icon="o-server"
              variant="pup">
    Servers
  </x-nav-link>

  {{-- USERS --}}
  <div class="mt-3">
    <div class="px-3 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">Users</div>

    <x-nav-link href="{{ route('admin.resellers.create') }}"
                :active="request()->routeIs('admin.resellers.create')"
                icon="o-plus"
                variant="mag">
      Add User
    </x-nav-link>

    <x-nav-link href="{{ route('admin.resellers.index') }}"
                :active="request()->routeIs('admin.resellers.index')"
                icon="o-user-group"
                variant="mag">
      Manage Users
    </x-nav-link>
  </div>

  {{-- LINES --}}
  <div class="mt-3">
    <div class="px-3 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
         x-show="!$root.sidebarCollapsed">Lines</div>

    <x-nav-link href="{{ route('admin.vpn-users.create') }}"
                :active="request()->routeIs('admin.vpn-users.create')"
                icon="o-plus-circle"
                variant="cya">
      Add Line
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-users.trial') }}"
                :active="request()->routeIs('admin.vpn-users.trial')"
                icon="o-clock"
                variant="cya">
      Generate Trial
    </x-nav-link>

    <x-nav-link href="{{ route('admin.vpn-users.index') }}"
                :active="request()->routeIs('admin.vpn-users.index')"
                icon="o-list-bullet"
                variant="cya">
      Manage Lines
    </x-nav-link>
  </div>
  
  {{-- APP --}}
<div class="mt-3">
  <div class="px-3 text-[11px] uppercase tracking-wide text-[var(--aio-sub)]"
       x-show="!$root.sidebarCollapsed">
    App
  </div>

  <x-nav-link
    href="{{ route('admin.app-builds') }}"
    :active="request()->routeIs('admin.app-builds*')"
    icon="o-arrow-up-tray"
    variant="neon"
  >
    App Builds
  </x-nav-link>
</div>

  {{-- SETTINGS --}}
  <x-nav-link href="{{ route('admin.settings') }}"
              :active="request()->routeIs('admin.settings')"
              icon="o-cog-6-tooth"
              variant="slate">
    Settings
  </x-nav-link>

  <hr class="my-4 aio-divider">

  {{-- CREDITS (Admin / Reseller only) --}}
  @auth
    @php
      $u = auth()->user();
      $isAdmin    = method_exists($u,'isAdmin') ? $u->isAdmin() : ($u->role === 'admin');
      $isReseller = method_exists($u,'isReseller') ? $u->isReseller() : ($u->role === 'reseller');
      $creditsUrl = $isAdmin ? route('admin.credits') : ($isReseller ? route('reseller.credits') : '#');
    @endphp
    @if ($isAdmin || $isReseller)
      <x-nav-link href="{{ $creditsUrl }}" icon="o-banknotes" variant="neon">
        Credits: {{ $u->credits }}
      </x-nav-link>
    @endif
  @endauth
</nav>