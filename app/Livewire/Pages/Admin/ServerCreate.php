<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Validate;
use Illuminate\Support\Facades\Log;

#[Layout('layouts.app')]
class ServerCreate extends Component
{
    #[Rule('required|string|max:100')]
    public $name;

    #[Rule('required|ip')]
    public $ip;

    #[Rule('required|in:OpenVPN,WireGuard')]
    public $protocol = 'OpenVPN';

    #[Rule('required|in:key,password')]
    public $sshType = 'key';

    #[Rule('required|integer|min:1|max:65535')]
    public $sshPort = 22;

    #[Rule('required|string')]
    public $sshUsername = 'root';

    public $sshPassword;

    #[Rule('nullable|integer')]
    public $port = 1194;

    #[Rule('nullable|in:udp,tcp')]
    public $transport = 'udp';

    #[Rule('nullable|string')]
    public $dns = '1.1.1.1';

    public $enableIPv6 = false;
    public $enableLogging = false;
    public $enableProxy = false;
    public $header1 = false;
    public $header2 = false;

public function create()
{
    Log::info('🛠️ Server creation triggered', ['ip' => $this->ip]);

    // Handle conditional validation for sshPassword
    if ($this->sshType === 'password') {
        $this->validate([
            'sshPassword' => 'required|string',
        ]);
    }

    // The rest of the validation is handled by the #[Rule] attributes

    $sshKeyPath = null;
    if ($this->sshType === 'key') {
        // Use storage_path helper to ensure correct path
        $sshKeyPath = storage_path('app/ssh_keys/id_rsa');

        if (!file_exists($sshKeyPath)) {
            Log::warning("⚠️ SSH key path missing: $sshKeyPath");
            session()->flash('error', "SSH key not found at expected path. Please check server configuration.");
        }
    }

    $server = VpnServer::create([
        'name'              => $this->name,
        'ip_address'        => $this->ip,
        'protocol'          => strtolower($this->protocol),
        'ssh_port'          => $this->sshPort,
        'ssh_user'          => $this->sshUsername,
        'ssh_type'          => $this->sshType,
        'ssh_password'      => $this->sshType === 'password' ? $this->sshPassword : null,
        'ssh_key'           => $sshKeyPath,
        'port'              => $this->port,
        'transport'         => $this->transport,
        'dns'               => $this->dns,
        'enable_ipv6'       => $this->enableIPv6,
        'enable_logging'    => $this->enableLogging,
        'enable_proxy'      => $this->enableProxy,
        'header1'           => $this->header1,
        'header2'           => $this->header2,
        'deployment_status' => 'queued',
        'deployment_log'    => '',
        'is_deploying'      => false,
    ]);

    // Safe dispatch
    if (!$server->is_deploying) {
        Log::info("🚀 Dispatching DeployVpnServer job for server #{$server->id}");
        dispatch(new DeployVpnServer($server));
    }

    return redirect()->route('admin.servers.install-status', $server);
}
    public function render()
    {
        return view('livewire.pages.admin.server-create');
    }
}
