{{-- resources/views/livewire/pages/admin/app-builds.blade.php --}}

<style>
  :root{
    --aio-neon:#3dff7f;--aio-cya:#39d9ff;--aio-pup:#9a79ff;--aio-mag:#ff4fd8;
    --aio-ink:#e6e8ef;--aio-sub:#9aa3b2;
  }
  .muted{color:var(--aio-sub)}
  .aio-divider{border-color:rgba(255,255,255,.08)}
  .aio-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:9999px;padding:.25rem .6rem;font-weight:600;font-size:.75rem;line-height:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}
  .pill-neon{background:rgba(61,255,127,.12);border-color:rgba(61,255,127,.35);color:var(--aio-ink)}
  .pill-cya{background:rgba(57,217,255,.12);border-color:rgba(57,217,255,.35);color:var(--aio-ink)}
  .pill-pup{background:rgba(154,121,255,.12);border-color:rgba(154,121,255,.35);color:var(--aio-ink)}
  .pill-mag{background:rgba(255,79,216,.12);border-color:rgba(255,79,216,.35);color:var(--aio-ink)}
  .pill-card{border-radius:.75rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08)}
  .outline-neon{box-shadow:inset 0 0 0 1px rgba(61,255,127,.25)}
  .outline-cya{box-shadow:inset 0 0 0 1px rgba(57,217,255,.25)}
  .outline-pup{box-shadow:inset 0 0 0 1px rgba(154,121,255,.25)}
  .outline-mag{box-shadow:inset 0 0 0 1px rgba(255,79,216,.25)}
  .shadow-glow{box-shadow:0 0 0 3px rgba(61,255,127,.15),0 6px 18px rgba(0,0,0,.35)}
  .aio-input{
    width:100%;
    border-radius:.75rem;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.10);
    padding:.6rem .75rem;
    color:var(--aio-ink);
    outline:none;
  }
  .aio-input:focus{box-shadow:0 0 0 3px rgba(57,217,255,.18);border-color:rgba(57,217,255,.35)}
  .aio-textarea{min-height:110px}
  .aio-help{font-size:.75rem;color:var(--aio-sub)}
</style>

<div class="space-y-6">
  {{-- HEADER --}}
  <div class="flex items-end justify-between">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">Upgrade App</h1>
      <p class="text-sm text-[var(--aio-sub)]">Upload a new APK. Devices will detect it via <span class="font-medium text-[var(--aio-ink)]">/api/app/latest</span>.</p>
    </div>

    <div class="flex items-center gap-2">
      @if($latestBuild)
        <span class="aio-pill pill-pup">
          Current: {{ $latestBuild->version_name }} ({{ $latestBuild->version_code }})
        </span>
      @else
        <span class="aio-pill bg-white/5 text-[var(--aio-sub)]">No active build</span>
      @endif
    </div>
  </div>

  {{-- FLASH --}}
  @if (session('success'))
    <div class="pill-card outline-neon p-4 text-[var(--aio-ink)]">
      <span class="aio-pill pill-neon">Success</span>
      <span class="ml-2">{{ session('success') }}</span>
    </div>
  @endif

  @if ($errors->any())
    <div class="pill-card outline-mag p-4 text-[var(--aio-ink)]">
      <span class="aio-pill pill-mag">Fix these</span>
      <ul class="list-disc pl-5 mt-2 space-y-1 text-sm">
        @foreach ($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif
  
  <button type="button" class="aio-pill pill-cya" wire:click="ping">
  Test Livewire Ping
</button>

  {{-- UPLOAD CARD --}}
  <div class="pill-card outline-cya p-5 space-y-4">
    <div class="flex items-center justify-between">
      <div class="text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
        <span class="aio-pill pill-cya">Upload</span>
        <span>New Build</span>
      </div>

      <div class="text-xs muted">
        Tip: customers must be on <span class="text-[var(--aio-ink)] font-medium">release-signed</span> builds for updates.
      </div>
    </div>

    <form wire:submit.prevent="upload" class="space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase tracking-wide muted mb-1">Version Code</label>
          <input type="number" wire:model.defer="version_code" class="aio-input" placeholder="e.g. 217">
          @error('version_code') <div class="text-sm text-red-300 mt-1">{{ $message }}</div> @enderror
        </div>

        <div>
          <label class="block text-xs uppercase tracking-wide muted mb-1">Version Name</label>
          <input type="text" wire:model.defer="version_name" class="aio-input" placeholder="e.g. 0.7.62">
          @error('version_name') <div class="text-sm text-red-300 mt-1">{{ $message }}</div> @enderror
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase tracking-wide muted mb-1">Release Notes</label>
        <textarea wire:model.defer="release_notes" class="aio-input aio-textarea" placeholder="What changed?"></textarea>
        @error('release_notes') <div class="text-sm text-red-300 mt-1">{{ $message }}</div> @enderror
        <div class="aio-help mt-1">Shown to the app. Keep it short and useful.</div>
      </div>

      <div class="flex items-center gap-3">
        <label class="aio-pill bg-white/5 hover:bg-white/10 cursor-pointer">
          <input type="checkbox" wire:model="mandatory" class="mr-2">
          Mandatory update
        </label>
        <span class="aio-help">If enabled, app should block use until updated.</span>
      </div>

      <div class="space-y-2">
        <label class="block text-xs uppercase tracking-wide muted">APK File</label>
        <div class="flex flex-col md:flex-row md:items-center gap-3">
          <input type="file" wire:model="apk" class="aio-input" style="padding:.45rem .6rem">
          <button type="submit" class="aio-pill pill-neon shadow-glow">
            Upload Build
          </button>
        </div>

        <div wire:loading wire:target="apk" class="aio-help">Uploading APK…</div>
        @error('apk') <div class="text-sm text-red-300">{{ $message }}</div> @enderror

        <div class="aio-help">
          Max 200MB. If you hit <span class="text-[var(--aio-ink)] font-medium">413</span>, increase NGINX <code>client_max_body_size</code>.
        </div>
      </div>
    </form>
  </div>

  {{-- HISTORY --}}
  <div class="pill-card outline-pup overflow-hidden">
    <div class="px-4 py-3 border-b aio-divider flex items-center justify-between">
      <div class="text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
        <span class="aio-pill pill-pup">History</span>
        <span>Recent Builds</span>
      </div>
      <div class="text-xs muted">{{ $buildHistory->count() }} rows</div>
    </div>

    <div class="overflow-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-white/5 sticky top-0 z-10">
          <tr class="text-[var(--aio-sub)] uppercase text-xs">
            <th class="px-4 py-2 text-left">Created</th>
            <th class="px-4 py-2 text-left">Version</th>
            <th class="px-4 py-2 text-left">Code</th>
            <th class="px-4 py-2 text-left">Active</th>
            <th class="px-4 py-2 text-left">Mandatory</th>
            <th class="px-4 py-2 text-left">SHA256</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/10">
          @forelse($buildHistory as $b)
            <tr class="hover:bg-white/5">
              <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $b->created_at?->diffForHumans() }}</td>
              <td class="px-4 py-2 text-[var(--aio-ink)] font-medium">{{ $b->version_name }}</td>
              <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $b->version_code }}</td>
              <td class="px-4 py-2">
                <span class="aio-pill {{ $b->is_active ? 'pill-neon' : 'bg-white/5 text-[var(--aio-sub)]' }}">
                  {{ $b->is_active ? 'Yes' : 'No' }}
                </span>
              </td>
              <td class="px-4 py-2">
                <span class="aio-pill {{ $b->mandatory ? 'pill-mag' : 'bg-white/5 text-[var(--aio-sub)]' }}">
                  {{ $b->mandatory ? 'Yes' : 'No' }}
                </span>
              </td>
              <td class="px-4 py-2">
                <code class="text-xs break-all text-[var(--aio-ink)]">{{ $b->sha256 }}</code>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-4 py-6 text-center muted">No builds uploaded yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>