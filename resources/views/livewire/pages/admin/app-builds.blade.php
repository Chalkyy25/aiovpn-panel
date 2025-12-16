{{-- resources/views/livewire/pages/admin/app-builds.blade.php --}}

<div>

    <h1>App Builds</h1>

    {{-- Success --}}
    @if (session()->has('success'))
        <div style="color: green; margin-bottom: 10px;">
            {{ session('success') }}
        </div>
    @endif

    {{-- Errors --}}
    @if ($errors->any())
        <div style="color: red; margin-bottom: 10px;">
            <ul>
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Ping --}}
    <button wire:click="ping" type="button">
        Test Livewire Ping
    </button>

    <hr style="margin: 20px 0;">

    {{-- Upload form --}}
    <form wire:submit.prevent="upload">

        <div>
            <label>Version Code</label><br>
            <input type="number" wire:model.defer="version_code">
        </div>

        <div>
            <label>Version Name</label><br>
            <input type="text" wire:model.defer="version_name">
        </div>

        <div>
            <label>Release Notes</label><br>
            <textarea wire:model.defer="release_notes"></textarea>
        </div>

        <div>
            <label>
                <input type="checkbox" wire:model.defer="mandatory">
                Mandatory Update
            </label>
        </div>

        <div>
            <label>APK File</label><br>
            <input type="file" wire:model="apk" accept=".apk">
        </div>

        <div style="margin-top: 10px;">
            <button type="submit" wire:loading.attr="disabled">
                Upload Build
            </button>
        </div>

        <div wire:loading>
            Uploading…
        </div>

    </form>

    <hr style="margin: 20px 0;">

    {{-- History --}}
    <h2>Build History</h2>

    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr>
                <th>Created</th>
                <th>Version</th>
                <th>Code</th>
                <th>Active</th>
                <th>Mandatory</th>
                <th>SHA256</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($buildHistory as $b)
                <tr>
                    <td>{{ $b->created_at?->diffForHumans() }}</td>
                    <td>{{ $b->version_name }}</td>
                    <td>{{ $b->version_code }}</td>
                    <td>{{ $b->is_active ? 'Yes' : 'No' }}</td>
                    <td>{{ $b->mandatory ? 'Yes' : 'No' }}</td>
                    <td style="max-width: 300px; word-break: break-all;">
                        {{ $b->sha256 }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No builds yet</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</div>