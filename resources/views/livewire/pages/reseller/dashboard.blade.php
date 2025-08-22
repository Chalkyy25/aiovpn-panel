<div class="max-w-7xl mx-auto p-6">
    <h2 class="text-2xl font-semibold mb-4">Reseller Dashboard</h2>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="p-4 rounded bg-gray-800 text-white">
            <div class="text-sm opacity-75">Credits</div>
            <div class="text-3xl font-bold">{{ $credits }}</div>
        </div>

        <div class="p-4 rounded bg-gray-800 text-white md:col-span-2">
            <div class="text-sm font-semibold mb-2">Recent Credit Activity</div>
            @forelse($recentTransactions as $tx)
                <div class="flex justify-between py-1 border-b border-gray-700/50">
                    <span class="text-sm">{{ $tx->reason ?? '—' }}</span>
                    <span class="text-sm font-mono {{ $tx->change >= 0 ? 'text-green-400' : 'text-red-400' }}">
                        {{ $tx->change > 0 ? '+' : '' }}{{ $tx->change }}
                    </span>
                </div>
            @empty
                <div class="text-sm text-gray-300">No transactions yet.</div>
            @endforelse
        </div>
    </div>
</div>