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
            'release_notes' => 'nullable|string',
            'apk'           => 'required|file|mimetypes:application/vnd.android.package-archive,application/octet-stream|max:200000',
        ];
    }

    public function mount(): void
    {
        $latest = AppBuild::where('is_active', true)->orderByDesc('version_code')->first();
        if ($latest) {
            // Optional: prefill next version code suggestion
            $this->version_code = (int) $latest->version_code + 1;
        }
    }

    public function upload(): void
    {
        $this->validate();

        $path = $this->apk->store('app-updates');

        $fullPath = Storage::disk('local')->path($path);
        $sha256 = hash_file('sha256', $fullPath);

        // Deactivate old builds (keep history)
        AppBuild::where('is_active', true)->update(['is_active' => false]);

        AppBuild::create([
            'version_code'  => $this->version_code,
            'version_name'  => $this->version_name,
            'apk_path'      => $path,
            'sha256'        => $sha256,
            'mandatory'     => $this->mandatory,
            'release_notes' => $this->release_notes,
            'is_active'     => true,
        ]);

        // reset form (keep next version_code suggestion)
        $this->reset(['version_name', 'mandatory', 'release_notes', 'apk']);

        session()->flash('success', 'Build uploaded. Devices will see it on next update check.');
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
            'latestBuild' => $this->latestBuild,
            'buildHistory' => $this->buildHistory,
        ]);
    }
}