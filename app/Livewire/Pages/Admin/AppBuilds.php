<?php

namespace App\Livewire\Pages\Admin;

use App\Models\AppBuild;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class AppBuilds extends Component
{
    use WithFileUploads;

    public int $version_code = 0;
    public string $version_name = '';
    public bool $mandatory = false;
    public ?string $release_notes = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $apk = null;

    public function rules(): array
    {
        return [
            'version_code'  => 'required|integer|min:1',
            'version_name'  => 'required|string|max:50',
            'mandatory'     => 'boolean',
            'release_notes' => 'nullable|string|max:2000',

            // MIME detection for APK is unreliable. Validate size here; enforce .apk manually.
            'apk' => 'required|file|max:200000', // ~200MB
        ];
    }

    public function mount(): void
    {
        if ($latest = $this->latestBuild) {
            $this->version_code = (int) $latest->version_code + 1;
        }
    }

    public function ping(): void
    {
        logger()->info('APP BUILDS PING HIT');
        session()->flash('success', 'Ping OK (Livewire is working).');
    }

    public function saveBuild(): void
    {
        logger()->info('APP BUILD SAVE START', [
            'version_code' => $this->version_code,
            'version_name' => $this->version_name,
            'mandatory'    => $this->mandatory,
            'has_file'     => (bool) $this->apk,
        ]);

        $this->validate();

        // Hard enforce .apk by filename (most reliable across devices/browsers)
        $original = strtolower((string) ($this->apk?->getClientOriginalName() ?? ''));
        if (!str_ends_with($original, '.apk')) {
            $this->addError('apk', 'File must be an .apk');
            return;
        }

        // Always store as .apk (prevents random .zip filenames)
        $safeVersion = preg_replace('/[^0-9A-Za-z._-]/', '-', $this->version_name) ?: 'build';
        $filename = "aiovpn-{$this->version_code}-{$safeVersion}-" . now()->format('YmdHis') . ".apk";

        $path = $this->apk->storeAs('app-updates', $filename, 'local');
        $fullPath = Storage::disk('local')->path($path);

        if (!is_file($fullPath)) {
            logger()->error('APP BUILD SAVE FAILED: file missing after storeAs()', [
                'path' => $path,
                'full' => $fullPath,
            ]);
            $this->addError('apk', 'Upload failed: file not found after saving.');
            return;
        }

        $sha256 = hash_file('sha256', $fullPath);

        // Deactivate previous active build
        AppBuild::query()->where('is_active', true)->update(['is_active' => false]);

        $build = AppBuild::create([
            'version_code'  => $this->version_code,
            'version_name'  => $this->version_name,
            'apk_path'      => $path,
            'sha256'        => $sha256,
            'mandatory'     => (bool) $this->mandatory,
            'release_notes' => $this->release_notes,
            'is_active'     => true,
        ]);

        logger()->info('APP BUILD SAVE DONE', [
            'id'   => $build->id,
            'path' => $path,
            'sha'  => $sha256,
        ]);

        // Reset form but bump version_code for next upload
        $this->reset(['version_name', 'mandatory', 'release_notes', 'apk']);
        $this->version_code = (int) $build->version_code + 1;

        session()->flash('success', "Build uploaded ✅ (ID: {$build->id})");
    }

    public function deactivateBuild(int $buildId): void
    {
        $build = AppBuild::findOrFail($buildId);

        // If it’s active, just flip it off.
        $build->update(['is_active' => false]);

        session()->flash('success', "Build {$build->version_name} ({$build->version_code}) deactivated ✅");
    }

public function deleteBuild(int $buildId): void
{
    $build = AppBuild::findOrFail($buildId);

    if ($build->is_active) {
        $this->addError('build', 'You can’t delete the active build. Deactivate it first.');
        return;
    }

    if ($build->apk_path) {
        Storage::disk('local')->delete($build->apk_path);
    }

    $build->delete();

    session()->flash('success', "Build deleted ✅");
}


    public function getLatestBuildProperty(): ?AppBuild
    {
        return AppBuild::where('is_active', true)->orderByDesc('version_code')->first();
    }

    public function getBuildHistoryProperty()
    {
        return AppBuild::orderByDesc('created_at')->limit(20)->get();
    }

    public function render(): ViewContract
    {
        return view('livewire.pages.admin.app-builds', [
            'latestBuild'  => $this->latestBuild,
            'buildHistory' => $this->buildHistory,
        ]);
    }
}
