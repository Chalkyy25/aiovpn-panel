<x-app-layout>
    <div class="max-w-4xl mx-auto py-8">
        <h1 class="text-2xl font-semibold mb-6">Upgrade App</h1>

        @if (session('success'))
            <div class="mb-4 p-3 rounded bg-green-100 text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form wire:submit.prevent="submit" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Version Code</label>
                <input type="number" wire:model.defer="version_code" class="w-full rounded border p-2">
                @error('version_code') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Version Name</label>
                <input type="text" wire:model.defer="version_name" class="w-full rounded border p-2">
                @error('version_name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Release Notes</label>
                <textarea wire:model.defer="release_notes" rows="4" class="w-full rounded border p-2"></textarea>
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model="mandatory">
                <span class="text-sm">Mandatory update</span>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">APK File</label>
                <input type="file" wire:model="apk">
                @error('apk') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                <div wire:loading wire:target="apk" class="text-sm text-gray-500 mt-1">Uploading…</div>
            </div>

            <button type="submit" class="px-4 py-2 rounded bg-black text-white">
                Upload Build
            </button>
        </form>
    </div>
</x-app-layout>