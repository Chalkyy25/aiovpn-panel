<x-filament-panels::page>
    <div class="grid gap-4">
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Current balance</div>
            <div class="text-3xl font-semibold text-gray-950 dark:text-white">{{ $this->balance }}</div>
        </div>

        <div class="rounded-xl bg-white p-2 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
