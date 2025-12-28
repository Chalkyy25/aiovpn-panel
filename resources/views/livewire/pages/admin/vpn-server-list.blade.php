<div class="p-6 space-y-4" wire:poll.30s="pollOnlineCounts">

  {{-- Flash --}}
  @if (session()->has('status-message'))
    <x-badge tone="green" class="inline-flex">
      {{ session('status-message') }}
    </x-badge>
  @endif

  {{-- Header --}}
  <div class="flex items-center justify-between gap-3">

    <x-button :href="route('admin.servers.create')" variant="primary" size="sm">
      + Add Server
    </x-button>
  </div>

  {{-- Empty --}}
  @if ($servers->isEmpty())
    <x-section-card title="No servers yet" subtitle="Click “Add Server” to get started.">
      <div class="text-sm text-[var(--aio-sub)]">
        No VPN servers found.
      </div>
    </x-section-card>
  @else

    @php $highlightId = request('highlight'); @endphp

    <x-table title="Servers" subtitle="Auto refresh: 30s">
      <x-slot:actions>
        <x-badge tone="slate" size="sm">Total: {{ $servers->count() }}</x-badge>
      </x-slot:actions>

      <thead>
        <tr>
          <th class="cell-nowrap">ID</th>
          <th>Name</th>
          <th class="cell-nowrap">IP Address</th>
          <th class="cell-nowrap">Protocol</th>
          <th class="cell-nowrap">Status</th>
          <th class="cell-right cell-nowrap">Actions</th>
        </tr>
      </thead>

      <tbody>
        @foreach ($servers as $server)
          <tr wire:key="server-{{ $server->id }}"
              class="{{ (string)$highlightId === (string)$server->id ? 'bg-[var(--aio-hover)]' : '' }}">

            <td class="cell-muted cell-nowrap">{{ $server->id }}</td>

            <td class="font-medium">
              {{ $server->name }}
            </td>

            <td class="cell-muted cell-nowrap">{{ $server->ip_address }}</td>

            {{-- Protocol --}}
            <td class="cell-nowrap">
              <x-badge tone="slate" size="sm">
                {{ strtoupper($server->protocol) }}
              </x-badge>
            </td>

            {{-- Status + online count --}}
            <td class="cell-nowrap">
              <div class="flex flex-wrap items-center gap-2">
                @if($server->status === 'online')
                  <x-badge tone="green" size="sm">Online</x-badge>
                @else
                  <x-badge tone="red" size="sm">Offline</x-badge>
                @endif

                @if ($server->online_user_count !== null)
                  <x-badge tone="blue" size="sm">
                    {{ $server->online_user_count }} online
                  </x-badge>
                @endif
              </div>
            </td>

            {{-- Actions --}}
            <td class="cell-right cell-nowrap">
              <div class="flex flex-wrap justify-end gap-2">
                <x-button :href="route('admin.servers.show', $server->id)" variant="light" size="sm">
                  View
                </x-button>

                <x-button :href="route('admin.servers.edit', $server->id)" variant="light" size="sm">
                  Edit
                </x-button>

                <x-button type="button" wire:click="syncServer({{ $server->id }})" variant="light" size="sm">
                  Sync
                </x-button>

                <x-button type="button"
                          wire:click="deleteServer({{ $server->id }})"
                          onclick="return confirm('Delete this server?')"
                          variant="danger"
                          size="sm">
                  Delete
                </x-button>
              </div>
            </td>

          </tr>
        @endforeach
      </tbody>
    </x-table>

  @endif
</div>
