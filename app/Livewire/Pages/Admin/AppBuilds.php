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

    /** @var mixed */
    public $apk = null;

    public function rules(): array
    {
        return [
            'version_code'  => 'required|integer|min:1',
            'version_name'  => 'required|string|max:50',
            'mandatory'     => 'boolean',
            'release_notes' => 'nullable|string|max:2000',
            'apk'           => 'required|file|mimes:apk|mimetypes:application/vnd.android.package-archive,application/zip,application/octet-stream,application/x-zip-compressed|max:200000',
        ];
    }

    public function ping(): void
    {
        logger()->info('APP BUILDS PING HIT');
        session()->flash('success', 'Ping OK (Livewire is working).');
    }

    public function mount(): void
    {
        $latest = AppBuild::where('is_active', true)->orderByDesc('version_code')->first();
        if ($latest) {
            $this->version_code = (int) $latest->version_code + 1;
        }
    }

    /**
     * IMPORTANT:
     * DO NOT name this method "upload" — it conflicts with Livewire's JS $wire.upload helper.
     */
    public function saveBuild(): void
    {
        logger()->info('APP BUILD SAVE START', [
            'version_code' => $this->version_code,
            'version_name' => $this->version_name,
            'mandatory'    => $this->mandatory,
            'has_file'     => (bool) $this->apk,
        ]);

        $this->validate();

        // Store under storage/app/app-updates
        $path = $this->apk->store('app-updates', 'local');
        $fullPath = Storage::disk('local')->path($path);

        if (!is_file($fullPath)) {
            logger()->error('APP BUILD SAVE FAILED: file missing after store()', ['path' => $path, 'full' => $fullPath]);
            $this->addError('apk', 'Upload failed: file not found after saving.');
            return;
        }

        $sha256 = hash_file('sha256', $fullPath);

        // deactivate previous active build
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

        // reset form but keep version_code (we’ll bump it)
        $this->reset(['version_name', 'mandatory', 'release_notes', 'apk']);
        $this->version_code = (int) $build->version_code + 1;

        session()->flash('success', "Build uploaded ✅ (ID: {$build->id})");
    }

    public function getLatestBuildProperty(): ?AppBuild
    {
        return AppBuild::where('is_active', true)->orderByDesc('version_code')->first();
    }

    public function getBuildHistoryProperty()
    {
        return AppBuild::orderByDesc('created_at')->limit(10)->get();
    }

    public function render(): ViewContract
    {
        return view('livewire.pages.admin.app-builds', [
            'latestBuild'   => $this->latestBuild,
            'buildHistory'  => $this->buildHistory,
        ]);
    }
}