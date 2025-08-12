<div class="bg-gray-900 text-white rounded shadow p-6">
    {{-- Errors --}}
    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-800 text-white rounded-md shadow">
            <h3 class="font-semibold">Form errors:</h3>
            <ul class="mt-2 list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Flash success (used on step 3 too) --}}
    @if (session()->has('success'))
        <div class="mb-4 p-4 bg-green-800 text-green-100 rounded-md shadow flex items-center">
            <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M5 13l4 4L19 7"/>
            </svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    {{-- Tabs / steps --}}
    <div class="flex border-b border-gray-700 mb-6 text-sm font-semibold space-x-6">
        <button type="button"
                wire:click="goTo(1)"
                class="pb-2 {{ $step === 1 ? 'border-b-2 border-blue-500 text-white' : 'text-gray-400 hover:text-white' }}">
            Details
        </button>
        <button type="button"
                wire:click="goTo(2)"
                class="pb-2 {{ $step === 2 ? 'border-b-2 border-blue-500 text-white' : 'text-gray-400 hover:text-white' }}">
            Review Purchase
        </button>
        <button type="button"
                wire:click="goTo(3)"
                class="pb-2 {{ $step === 3 ? 'border-b-2 border-blue-500 text-white' : 'text-gray-400 hover:text-white' }}">
            Done
        </button>
    </div>

    {{-- STEP 1: DETAILS --}}
    @if ($step === 1)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Username --}}
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-300">Username</label>
                <input type="text" wire:model.lazy="username"
                       placeholder="Auto-generated if left as is"
                       class="w-full bg-gray-800 border {{ $errors->has('username') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500"/>
                @error('username')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Duration --}}
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-300">Duration</label>
                <select wire:model="expiry"
                        class="w-full bg-gray-800 border {{ $errors->has('expiry') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                    <option value="1m">1 Month</option>
                    <option value="3m">3 Months</option>
                    <option value="6m">6 Months</option>
                    <option value="12m">12 Months</option>
                </select>
                @error('expiry')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Package --}}
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-300">Package</label>
                <select wire:model="packageId"
                        class="w-full bg-gray-800 border {{ $errors->has('packageId') ? 'border-red-500' : 'border-gray-600' }} rounded px-3 py-2 focus:outline-none focus:ring focus:ring-blue-500">
                    @foreach($packages as $p)
                        <option value="{{ $p->id }}">
                            {{ $p->name }} — {{ $p->price_credits }} credits (max {{ $p->max_connections }} conn)
                        </option>
                    @endforeach
                </select>
                @error('packageId')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                @enderror

                <div class="mt-2 text-xs text-gray-300">
                    <div>Cost: <span class="font-semibold">{{ $priceCredits }}</span> credits</div>
                    <div>Your credits: <span class="font-semibold">{{ $adminCredits }}</span></div>
                    @if($adminCredits < $priceCredits)
                        <div class="text-red-400 mt-1">Not enough credits for this package.</div>
                    @endif
                </div>
            </div>

            {{-- Servers --}}
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-300">Assign to Servers</label>
                <div class="space-y-2 {{ $errors->has('selectedServers') ? 'border border-red-500 p-2 rounded' : '' }}">
                    @error('selectedServers')
                    <p class="mb-2 text-sm text-red-500">{{ $message }}</p>
                    @enderror

                    @forelse ($servers as $server)
                        <label class="flex items-center space-x-2 text-sm text-gray-300">
                            <input type="checkbox" wire:model="selectedServers" value="{{ $server->id }}"
                                   class="text-blue-500 bg-gray-700 border-gray-600 rounded focus:ring focus:ring-blue-500"/>
                            <span>{{ $server->name }} ({{ $server->ip_address }})</span>
                        </label>
                    @empty
                        <div class="text-gray-400 text-sm">No servers available.</div>
                    @endforelse
                </div>
            </div>

            {{-- Step controls --}}
            <div class="md:col-span-2 text-right pt-4">
                <button type="button"
                        wire:click="next"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium inline-flex items-center space-x-2"
                        @disabled($adminCredits < $priceCredits)
                >
                    <span>Next</span>
                </button>
            </div>
        </div>
    @endif

    {{-- STEP 2: REVIEW --}}
    @if ($step === 2)
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-800 rounded p-4">
                    <h4 class="font-semibold mb-3">Summary</h4>
                    <dl class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-gray-300">Username</dt>
                            <dd class="font-mono text-gray-100">{{ $username }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-300">Password</dt>
                            <dd class="text-gray-400 italic">Will be generated on purchase</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-300">Duration</dt>
                            <dd class="text-gray-100">
                                @switch($expiry)
                                    @case('1m') 1 Month @break
                                    @case('3m') 3 Months @break
                                    @case('6m') 6 Months @break
                                    @case('12m') 12 Months @break
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-300 mb-1">Servers</dt>
                            <dd class="text-gray-100">
                                @php
                                    $serverMap = $servers->keyBy('id');
                                @endphp
                                @if (count($selectedServers))
                                    <ul class="list-disc pl-5 space-y-1">
                                        @foreach ($selectedServers as $sid)
                                            @if ($serverMap->has($sid))
                                                <li>{{ $serverMap[$sid]->name }} ({{ $serverMap[$sid]->ip_address }})</li>
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
                    <h4 class="font-semibold mb-3">Credits</h4>
                    <div class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-300">Package</span>
                            <span class="text-gray-100">
                                @php
                                    $pkg = $packages->firstWhere('id', $packageId);
                                @endphp
                                {{ $pkg?->name ?? '—' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-300">Cost</span>
                            <span class="text-gray-100">{{ $priceCredits }} credits</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-300">Your balance</span>
                            <span class="{{ $adminCredits < $priceCredits ? 'text-red-300' : 'text-green-300' }}">
                                {{ $adminCredits }} credits
                            </span>
                        </div>
                        @if($adminCredits >= $priceCredits)
                            <div class="flex justify-between border-t border-gray-700 pt-2">
                                <span class="text-gray-300">Balance after</span>
                                <span class="text-gray-100">{{ $adminCredits - $priceCredits }} credits</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="text-right space-x-3">
                <button type="button"
                        wire:click="back"
                        class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded font-medium">
                    Back
                </button>

                <button type="button"
                        wire:click="purchase"
                        class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded font-medium inline-flex items-center space-x-2"
                        @disabled($adminCredits < $priceCredits)
                >
                    <span>Purchase</span>
                </button>
            </div>
        </div>
    @endif

    {{-- STEP 3: DONE --}}
    @if ($step === 3)
        <div class="text-center space-y-4 py-8">
            <div class="text-4xl">🎉</div>
            <h3 class="text-xl font-semibold">VPN user created</h3>

            @if (session()->has('success'))
                <p class="text-green-200">{{ session('success') }}</p>
            @endif

            <div class="flex items-center justify-center space-x-3 mt-4">
                <a href="{{ route('admin.vpn-users.index') }}"
                   class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded font-medium">
                    View All Users
                </a>
                <button type="button"
                        wire:click="$set('step', 1)"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-medium">
                    Create Another
                </button>
            </div>
        </div>
    @endif
</div>