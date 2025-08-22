<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\DeployVpnServer;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

#[Layout('layouts.app')]
class ServerCreate extends Component
{
    // ===== Required / core fields (exist in your current schema) =====
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

    #[Rule('nullable|integer|min:1|max:65535')]
    public $port = 1194;

    #[Rule('nullable|in:udp,tcp')]
    public $transport = 'udp';

    #[Rule('nullable|string')]
    public $dns = '1.1.1.1';

    public $enableIPv6    = false;
    public $enableLogging = false;
    public $enableProxy   = false;
    public $header1       = false;
    public $header2       = false;

    // ===== Optional “future” fields — only saved if columns exist =====
    // (Add migration later; safe to keep here meanwhile)
    public $provider            = 'AIO VPN';
    public $region              = 'N/A';
    public $country_code        = 'N/A';
    public $city                = 'N/A';
    public $tags                = null;  // comma separated in UI -> saved as JSON array when column exists
    public $enabled             = true;
    public $ipv6_enabled        = null;  // alias of enable_ipv6 if you later rename
    public $mtu                 = null;
    public $api_endpoint        = 'N/A';
    public $api_token           = 'N/A';
    public $monitoring_enabled  = true;
    public $health_check_cmd    = 'systemctl is-active openvpn@server';
    public $install_branch      = 'stable';
    public $statusOverride      = null;  // if you want to seed a status immediately
    public $max_clients         = null;
    public $rate_limit_mbps     = null;
    public $allow_split_tunnel  = false;
    public $ovpn_cipher         = 'AES-256-GCM';
    public $ovpn_compression    = 'lz4-v2 / none';
    public $wg_public_key       = 'N/A';
    public $wg_private_key      = 'N/A';
    public $notes               = null;

    public function create()
{
    // 1) Validate everything declared with #[Rule]
    //    (Livewire v3 attribute rules run only when you call $this->validate())
    $this->validate();

    // 1b) Extra rule when using password auth
    if ($this->sshType === 'password') {
        $this->validate(['sshPassword' => 'required|string']);
    }

    // 1c) Belt-and-suspenders: trim + guard name
    $this->name = trim((string) $this->name);
    if ($this->name === '') {
        // You can also return with an error instead, but this prevents null inserts.
        $this->addError('name', 'The Server Name is required.');
        return;
    }

    // 2) Build base data (current schema)
    $data = [
        'name'               => $this->name,
        'ip_address'         => $this->ip,
        'protocol'           => strtolower($this->protocol),
        'ssh_port'           => (int) $this->sshPort,
        'ssh_user'           => $this->sshUsername,
        'ssh_type'           => $this->sshType,
        'ssh_password'       => $this->sshType === 'password' ? $this->sshPassword : null,
        'ssh_key'            => $this->sshType === 'key' ? storage_path('app/ssh_keys/id_rsa') : null,
        'port'               => $this->port ?: null,
        'transport'          => $this->transport ?: null,
        'dns'                => $this->dns ?: null,
        'enable_ipv6'        => (bool) $this->enableIPv6,
        'enable_logging'     => (bool) $this->enableLogging,
        'enable_proxy'       => (bool) $this->enableProxy,
        'header1'            => (bool) $this->header1,
        'header2'            => (bool) $this->header2,
        'deployment_status'  => 'queued',
        'deployment_log'     => '',
        'status'             => 'pending',
    ];

    // 3) Add optional/future columns only if they exist
    $maybe = [
        'provider'           => $this->provider,
        'region'             => $this->region,
        'country_code'       => $this->country_code,
        'city'               => $this->city,
        'enabled'            => (bool) $this->enabled,
        'ipv6_enabled'       => $this->ipv6_enabled,
        'mtu'                => $this->mtu ? (int) $this->mtu : null,
        'api_endpoint'       => $this->api_endpoint,
        'api_token'          => $this->api_token,
        'monitoring_enabled' => (bool) $this->monitoring_enabled,
        'health_check_cmd'   => $this->health_check_cmd,
        'install_branch'     => $this->install_branch,
        'max_clients'        => $this->max_clients ? (int) $this->max_clients : null,
        'rate_limit_mbps'    => $this->rate_limit_mbps ? (int) $this->rate_limit_mbps : null,
        'allow_split_tunnel' => (bool) $this->allow_split_tunnel,
        'ovpn_cipher'        => $this->ovpn_cipher,
        'ovpn_compression'   => $this->ovpn_compression,
        'wg_public_key'      => $this->wg_public_key,
        'wg_private_key'     => $this->wg_private_key,
        'notes'              => $this->notes,
        'is_deploying'       => false,
    ];

    if (\Illuminate\Support\Facades\Schema::hasColumn('vpn_servers', 'tags')) {
        $maybe['tags'] = $this->normalizeTags($this->tags);
    }
    if (!empty($this->statusOverride) && \Illuminate\Support\Facades\Schema::hasColumn('vpn_servers', 'status')) {
        $maybe['status'] = $this->statusOverride;
    }
    foreach ($maybe as $column => $value) {
        if (\Illuminate\Support\Facades\Schema::hasColumn('vpn_servers', $column)) {
            $data[$column] = $value;
        }
    }

    // 4) Warn if SSH key missing (non‑blocking)
    if ($data['ssh_key'] && !file_exists($data['ssh_key'])) {
        Log::warning("⚠️ SSH key path missing: {$data['ssh_key']}");
        session()->flash('error', 'SSH key file not found (storage/app/ssh_keys/id_rsa). You can still save, but deployment may fail.');
    }

    // (Optional) One-time debug to verify 'name' is set
    Log::info('Creating VpnServer with payload', ['name' => $data['name'], 'ip_address' => $data['ip_address']]);

    $server = VpnServer::create($data);

    Log::info("🚀 Dispatching DeployVpnServer job for server #{$server->id}");
    dispatch(new DeployVpnServer($server));

    return redirect()->route('admin.servers.install-status', $server);
}

    public function render()
    {
        return view('livewire.pages.admin.server-create');
    }

    /**
     * Convert comma/space separated tags into a clean array.
     */
    private function normalizeTags($raw)
    {
        if (!$raw) return null;

        // Accept "uk, london  , edge  " or "uk london edge"
        $clean = collect(preg_split('/[,\s]+/', trim($raw)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $clean ?: null;
    }
}