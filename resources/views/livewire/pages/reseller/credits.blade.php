<div class="max-w-5xl mx-auto p-6">
    <h2 class="text-xl font-semibold mb-4">Credits</h2>

    <div class="mb-6 p-4 rounded bg-gray-800 text-white">
        <div class="text-sm opacity-75">Current balance</div>
        <div class="text-3xl font-bold">{{ $balance }}</div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="text-left px-4 py-2">Date</th>
                    <th class="text-left px-4 py-2">Reason</th>
                    <th class="text-right px-4 py-2">Change</th>
                </tr>
            </thead>
            <tbody>
            @foreach($transactions as $tx)
                <tr class="border-t border-gray-200 dark:border-gray-800">
                    <td class="px-4 py-2">{{ $tx->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-2">{{ $tx->reason ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono {{ $tx->change >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $tx->change > 0 ? '+' : '' }}{{ $tx->change }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="p-4">
            {{ $transactions->links() }}
        </div>
    </div>
</div>