<x-app-layout>
  <x-slot name="header">
    <h2 class="text-xl font-semibold text-[var(--aio-ink)]">Create Package</h2>
  </x-slot>

  <div class="max-w-2xl mx-auto py-6">
    <form method="POST" action="{{ route('packages.store') }}" class="space-y-4 aio-card p-6">
      @csrf

      <div>
        <label class="form-label">Package Name</label>
        <input type="text" name="name" class="form-input" required>
      </div>

      <div>
        <label class="form-label">Credits Cost</label>
        <input type="number" name="price_credits" class="form-input" required>
      </div>

      <div>
        <label class="form-label">Max Connections (0 = Unlimited)</label>
        <input type="number" name="max_connections" class="form-input" value="1" required>
      </div>

      <button type="submit" class="btn">Save Package</button>
    </form>
  </div>
</x-app-layout>