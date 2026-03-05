<div wire:poll.2s>
    @if(!$server)
        <div class="text-sm text-gray-500">
            Server not found.
        </div>
    @else
        <div class="space-y-3">
            <div class="text-sm">
                <div><span class="font-medium">Server:</span> {{ $server->name }}</div>
                <div><span class="font-medium">Status:</span> {{ $server->deployment_status ?? '—' }} @if(($server->is_deploying ?? false)) (deploying) @endif</div>
                <div><span class="font-medium">Updated:</span> {{ optional($server->updated_at)->toDateTimeString() ?? '—' }}</div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 max-h-96 overflow-auto">
                <pre class="text-xs font-mono whitespace-pre-wrap">{{ $log !== '' ? $log : 'No log output yet.' }}</pre>
            </div>
        </div>
    @endif
</div>
