<x-app-layout>
  <x-slot name="header">
    <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Packages</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto py-6 space-y-4">
    <a href="{{ route('admin.packages.create') }}" class="btn">+ New Package</a>

    <div class="aio-card overflow-x-auto">
      <table class="min-w-full text-sm table-dark">
        <thead>
          <tr>
            <th>Name</th>
            <th>Credits</th>
            <th>Max Connections</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          @foreach($packages as $package)
            <tr>
              <td>{{ $package->name }}</td>
              <td>{{ $package->price_credits }}</td>
              <td>{{ $package->max_connections_text }}</td>
              <td>{{ $package->created_at->diffForHumans() }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</x-app-layout>