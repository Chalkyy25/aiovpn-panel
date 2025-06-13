<?php
//         $this->attributes['deployment_status'] = Str::lower($value);
namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Storage;
use App\Jobs\DeployVpnServer;

class ServerLog extends Component
{
    public VpnServer $server;

    public function mount(VpnServer $server)
    {
        $this->server = $server;
    }

    public function clearLog()
    {
        $this->server->update(['deployment_log' => null]);
    }

    public function refreshLog()
    {
        $this->server->refresh();
    }

    public function downloadLog()
    {
        $filename = 'vpn-server-log-' . $this->server->id . '.txt';
        Storage::disk('local')->put($filename, $this->server->deployment_log ?? 'No logs.');
        return response()->download(storage_path('app/' . $filename))->deleteFileAfterSend(true);
    }

    public function render()
    {
        $this->server->refresh();
        return view('livewire.admin.server-log', [
            'log' => $this->server->deployment_log,
            'status' => $this->server->deployment_status,
        ]);
    }
    public function redeploy()
{
    dispatch(new DeployVpnServer($this->server));
    $this->server->update([
        'deployment_status' => 'pending',
        'deployment_log' => "Redeployment triggered from panel..."
    ]);
}


}
