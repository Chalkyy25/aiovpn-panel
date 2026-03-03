<x-filament-panels::page>
    @php($user = $this->user)

    <div class="grid gap-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Name</div>
            <div class="text-lg font-medium text-gray-950 dark:text-white">{{ $user?->name ?? '—' }}</div>
        </div>

        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Email</div>
            <div class="text-lg font-medium text-gray-950 dark:text-white">{{ $user?->email ?? '—' }}</div>
        </div>

        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Credits</div>
            <div class="text-lg font-medium text-gray-950 dark:text-white">{{ (int) ($user?->credits ?? 0) }}</div>
        </div>
    </div>
</x-filament-panels::page>
