<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnUser;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DownloadConfig extends Component
{
    public VpnUser $vpnUser;

    public function mount()
    {
        if (auth()->user()->isAdmin()) {
            // expect ?user_id=xx
            $userId = request()->get('user_id');
            $this->vpnUser = VpnUser::findOrFail($userId);
        } else {
            $this->vpnUser = auth()->user()->vpnUser;
        }
    }

    public function download()
    {
        $filename = "{$this->vpnUser->username}.ovpn";
        $path = "ovpn/{$filename}";

        if (!Storage::exists($path)) {
            session()->flash('error', 'Config not found.');
            return;
        }

        return response()->download(storage_path("app/{$path}"));
    }

    public function render()
    {
        return view('livewire.pages.client.download-config');
    }
}