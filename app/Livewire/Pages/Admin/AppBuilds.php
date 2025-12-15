<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\AppBuild;
use Illuminate\Support\Facades\Storage;

class AppBuilds extends Component
{
    use WithFileUploads;

    public int $version_code;
    public string $version_name;
    public bool $mandatory = false;
    public ?string $release_notes = null;
    public $apk;

    protected function rules()
    {
        return [
            'version_code'  => 'required|integer|min:1',
            'version_name'  => 'required|string|max:50',
            'mandatory'     => 'boolean',
            'release_notes' => 'nullable|string',
            'apk'           => 'required|file|mimetypes:application/vnd.android.package-archive,application/octet-stream|max:200000',
        ];
    }

    public function submit()
    {
        $this->validate();

        $path = $this->apk->store('app-updates');

        $fullPath = Storage::disk('local')->path($path);
        $sha256 = hash_file('sha256', $fullPath);

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

        $this->reset(['version_code', 'version_name', 'mandatory', 'release_notes', 'apk']);

        session()->flash('success', 'Build uploaded successfully.');
    }

    public function render()
    {
        return view('livewire.pages.admin.app-builds');
    }
}