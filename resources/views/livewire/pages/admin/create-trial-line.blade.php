<div class="aio-card">
    <div class="aio-card-body">
    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-900/20 text-red-100 border border-red-700 rounded-md">
            <div class="font-semibold mb-1">Form errors</div>
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 p-4 bg-green-900/20 text-green-100 border border-green-700 rounded-md">
            {{ session('success') }}
        </div>
    @endif

    {{-- Steps --}}
    <div class="flex border-b border-[var(--aio-border)] mb-6 text-sm font-semibold space-x-6">
        <button type="button" wire:click="goTo(1)" class="pb-2 {{ $step===1?'border-b-2 border-[var(--aio-accent)] text-[var(--aio-ink)]':'text-[var(--aio-sub)] hover:text-[var(--aio-ink)]' }}">
            Details
        </button>
        <button type="button" wire:click="goTo(2)" class="pb-2 {{ $step===2?'border-b-2 border-[var(--aio-accent)] text-[var(--aio-ink)]':'text-[var(--aio-sub)] hover:text-[var(--aio-ink)]' }}">
            Review
        </button>
        <button type="button" class="pb-2 {{ $step===3?'border-b-2 border-[var(--aio-accent)] text-[var(--aio-ink)]':'text-[var(--aio-sub)]' }}">
            Done
        </button>
    </div>

    {{-- Step 1 --}}
    @if ($step === 1)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium mb-1 text-[var(--aio-sub)]">Username</label>
                <input type="text" wire:model.lazy="username" class="form-input w-full"/>
                <p class="mt-1 text-xs text-[var(--aio-sub)]">Will expire automatically in 24 hours.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-2 text-[var(--aio-sub)]">Assign to Servers</label>
                <div class="space-y-2 {{ $errors->has('selectedServers') ? 'border border-red-500 p-2 rounded' : '' }}">
                    @foreach ($servers as $server)
                        <label class="flex items-center space-x-2 text-sm text-[var(--aio-ink)]">
                            <input type="checkbox" value="{{ $server->id }}" wire:model="selectedServers"
                                   class="h-4 w-4 rounded border-[var(--aio-border)]"/>
                            <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="md:col-span-2 text-right">
                <x-button type="button" wire:click="next" variant="primary">Next</x-button>
            </div>
        </div>
    @endif

    {{-- Step 2 --}}
    @if ($step === 2)
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-[var(--aio-hover)] rounded p-4">
                    <h4 class="font-semibold mb-3 text-[var(--aio-ink)]">Summary</h4>
                    <dl class="text-sm space-y-2">
                        <div class="flex justify-between"><dt class="text-[var(--aio-sub)]">Username</dt><dd class="font-mono text-[var(--aio-ink)]">{{ $username }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--aio-sub)]">Duration</dt><dd class="text-[var(--aio-ink)]">24 hours</dd></div>
                        <div>
                            <dt class="text-[var(--aio-sub)] mb-1">Servers</dt>
                            <dd class="text-[var(--aio-ink)]">
                                @if (count($selectedServers))
                                    <ul class="list-disc pl-5 space-y-1">
                                        @php $map = $servers->keyBy('id'); @endphp
                                        @foreach ($selectedServers as $sid)
                                            @if ($map->has($sid))
                                                <li>{{ $map[$sid]->name }} ({{ $map[$sid]->ip_address }})</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="text-[var(--aio-sub)]">No servers selected</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-[var(--aio-hover)] rounded p-4">
                    <h4 class="font-semibold mb-3 text-[var(--aio-ink)]">Notes</h4>
                    <ul class="list-disc pl-5 text-sm space-y-1 text-[var(--aio-sub)]">
                        <li>Max 1 device connection.</li>
                        <li>Expires automatically after 24 hours.</li>
                        <li>No credits are deducted for trials.</li>
                    </ul>
                </div>
            </div>

            <div class="text-right space-x-3">
                <x-button type="button" wire:click="back" variant="secondary">Back</x-button>
                <x-button type="button" wire:click="createTrial" variant="primary">
                    Create Trial
                </x-button>
            </div>
        </div>
    @endif

    {{-- Step 3 --}}
    @if ($step === 3)
        <div class="text-center space-y-4 py-8">
            <div class="text-4xl">🎉</div>
            <h3 class="text-xl font-semibold text-[var(--aio-ink)]">Trial line created</h3>
            @if (session('success')) <p class="text-green-400">{{ session('success') }}</p> @endif

            <div class="flex items-center justify-center gap-3 mt-4">
                <x-button href="{{ route('admin.vpn-users.index') }}" variant="success">View Lines</x-button>
                <x-button type="button" wire:click="$set('step', 1)" variant="primary">Create Another</x-button>
            </div>
        </div>
    @endif
    </div>
</div>