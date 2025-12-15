<div class="max-w-5xl mx-auto py-8 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Upgrade App</h1>
        @if($latestBuild)
            <div class="text-sm text-gray-600">
                Current: <span class="font-medium">{{ $latestBuild->version_name }}</span>
                ({{ $latestBuild->version_code }})
            </div>
        @endif
    </div>

    @if (session('success'))
        <div class="p-3 rounded bg-green-100 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-3 rounded bg-red-100 text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded shadow p-6 space-y-4">
        <h2 class="text-lg font-semibold">Upload new APK</h2>

        <form wire:submit.prevent="upload" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Version Code</label>
                    <input type="number" wire:model.defer="version_code" class="w-full rounded border p-2">
                    @error('version_code') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Version Name</label>
                    <input type="text" wire:model.defer="version_name" class="w-full rounded border p-2">
                    @error('version_name') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Release Notes</label>
                <textarea wire:model.defer="release_notes" rows="4" class="w-full rounded border p-2"></textarea>
                @error('release_notes') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model="mandatory" class="rounded border">
                <span class="text-sm">Mandatory update</span>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">APK File</label>
                <input type="file" wire:model="apk">
                <div wire:loading wire:target="apk" class="text-sm text-gray-500 mt-1">Uploading…</div>
                @error('apk') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="px-4 py-2 rounded bg-black text-white">
                Upload Build
            </button>
        </form>
    </div>

    <div class="bg-white rounded shadow p-6">
        <h2 class="text-lg font-semibold mb-3">Recent builds</h2>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left border-b">
                        <th class="py-2 pr-4">Created</th>
                        <th class="py-2 pr-4">Version</th>
                        <th class="py-2 pr-4">Code</th>
                        <th class="py-2 pr-4">Active</th>
                        <th class="py-2 pr-4">Mandatory</th>
                        <th class="py-2 pr-4">SHA256</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($buildHistory as $b)
                        <tr class="border-b">
                            <td class="py-2 pr-4">{{ $b->created_at?->diffForHumans() }}</td>
                            <td class="py-2 pr-4 font-medium">{{ $b->version_name }}</td>
                            <td class="py-2 pr-4">{{ $b->version_code }}</td>
                            <td class="py-2 pr-4">{{ $b->is_active ? 'Yes' : 'No' }}</td>
                            <td class="py-2 pr-4">{{ $b->mandatory ? 'Yes' : 'No' }}</td>
                            <td class="py-2 pr-4">
                                <code class="text-xs break-all">{{ $b->sha256 }}</code>
                            </td>
                        </tr>
                    @endforeach

                    @if($buildHistory->isEmpty())
                        <tr><td colspan="6" class="py-3 text-gray-600">No builds yet.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>