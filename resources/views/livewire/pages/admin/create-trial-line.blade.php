<div class="bg-gray-900 text-white rounded shadow p-6">
    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-800 text-white rounded-md">
            <div class="font-semibold mb-1">Form errors</div>
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 p-4 bg-green-800 text-green-100 rounded-md">
            {{ session('success') }}
        </div>
    @endif

    {{-- Steps --}}
    <div class="flex border-b border-gray-700 mb-6 text-sm font-semibold space-x-6">
        <button type="button" wire:click="goTo(1)" class="pb-2 {{ $step===1?'border-b-2 border-blue-500 text-white':'text-gray-400 hover:text-white' }}">
            Details
        </button>
        <button type="button" wire:click="goTo(2)" class="pb-2 {{ $step===2?'border-b-2 border-blue-500 text-white':'text-gray-400 hover:text-white' }}">
            Review
        </button>
        <button type="button" class="pb-2 {{ $step===3?'border-b-2 border-blue-500 text-white':'text-gray-400' }}">
            Done
        </button>
    </div>

    {{-- Step 1 --}}
    @if ($step === 1)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-300">Username</label>
                <input type="text" wire:model.lazy="username"
                       class="w-full bg-gray-800 border {{ $errors->has('username')?'border-red-500':'border-gray-600' }} rounded px-3 py-2"/>
                <p class="mt-1 text-xs text-gray-400">Will expire automatically in 24 hours.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-2 text-gray-300">Assign to Servers</label>
                <div class="space-y-2 {{ $errors->has('selectedServers') ? 'border border-red-500 p-2 rounded' : '' }}">
                    @foreach ($servers as $server)
                        <label class="flex items-center space-x-2 text-sm text-gray-300">
                            <input type="checkbox" value="{{ $server->id }}" wire:model="selectedServers"
                                   class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring"/>
                            <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="md:col-span-2 text-right">
                <button type="button" wire:click="next"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">Next</button>
            </div>
        </div>
    @endif

    {{-- Step 2 --}}
    @if ($step === 2)
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-800 rounded p-4">
                    <h4 class="font-semibold mb-3">Summary</h4>
                    <dl class="text-sm space-y-2">
                        <div class="flex justify-between"><dt class="text-gray-300">Username</dt><dd class="font-mono">{{ $username }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-300">Duration</dt><dd>24 hours</dd></div>
                        <div>
                            <dt class="text-gray-300 mb-1">Servers</dt>
                            <dd class="text-gray-100">
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
                                    <span class="text-gray-400">No servers selected</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-gray-800 rounded p-4">
                    <h4 class="font-semibold mb-3">Notes</h4>
                    <ul class="list-disc pl-5 text-sm space-y-1 text-gray-300">
                        <li>Max 1 device connection.</li>
                        <li>Expires automatically after 24 hours.</li>
                        <li>No credits are deducted for trials.</li>
                    </ul>
                </div>
            </div>

            <div class="text-right space-x-3">
                <button type="button" wire:click="back" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded">Back</button>
                <button type="button" wire:click="createTrial" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded">
                    Create Trial
                </button>
            </div>
        </div>
    @endif

    {{-- Step 3 --}}
    @if ($step === 3)
        <div class="text-center space-y-4 py-8">
            <div class="text-4xl">🎉</div>
            <h3 class="text-xl font-semibold">Trial line created</h3>
            @if (session('success')) <p class="text-green-200">{{ session('success') }}</p> @endif

            <div class="flex items-center justify-center gap-3 mt-4">
                <a href="{{ route('admin.vpn-users.index') }}"
                   class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">View Lines</a>
                <button type="button" wire:click="$set('step', 1)"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">Create Another</button>
            </div>
        </div>
    @endif
</div>