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
    // ===== Required / core fields =====
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

    // ===== Location & metadata =====
    #[Rule('nullable|string|max:255')]
    public $provider = 'AIO VPN';

    #[Rule('nullable|string|max:255')]
    public $region = null; // e.g. "Europe", "US-East"

    #[Rule('nullable|string|size:2')]
    public $country_code = null; // ISO2: "DE", "ES", "GB", ...

    #[Rule('nullable|string|max:80')]
    public $city = null; // e.g. "Frankfurt"

    public $tags = null;  // comma/space separated string in UI

    public $enabled            = true;
    public $ipv6_enabled       = null; // optional alias for future schema
    public $mtu                = null;
    public $api_endpoint       = null;
    public $api_token          = null;
    public $monitoring_enabled = true;
    public $health_check_cmd   = 'systemctl is-active openvpn-server@server';
    public $install_branch     = 'stable';
    public $statusOverride     = null;
    public $max_clients        = null;
    public $rate_limit_mbps    = null;
    public $allow_split_tunnel = false;
    public $ovpn_cipher        = 'AES-256-GCM';
    public $ovpn_compression   = 'lz4-v2 / none';

    // WireGuard fields (server-level, not per-client)
    public $wg_public_key  = null;
    public $wg_private_key = null;
    public $notes          = null;

    public function create()
    {
        // 1) Validate attributes with #[Rule]
        $this->validate();

        // Extra rule when using password auth
        if ($this->sshType === 'password') {
            $this->validate(['sshPassword' => 'required|string']);
        }

        // Trim & guard server name
        $this->name = trim((string) $this->name);
        if ($this->name === '') {
            $this->addError('name', 'The Server Name is required.');
            return;
        }

        // Normalise protocol / transport before saving
        $protocol  = strtolower($this->protocol);
        $transport = $this->transport ? strtolower($this->transport) : null;

        if ($protocol === 'wireguard') {
            // No udp/tcp concept for WireGuard
            $transport = null;
        }

        // 2) Build base data (columns that definitely exist)
        $data = [
            'name'              => $this->name,
            'ip_address'        => $this->ip,
            'protocol'          => $protocol,
            'ssh_port'          => (int) $this->sshPort,
            'ssh_user'          => $this->sshUsername,
            'ssh_type'          => $this->sshType,
            'ssh_password'      => $this->sshType === 'password' ? $this->sshPassword : null,
            'ssh_key'           => $this->sshType === 'key' ? 'id_rsa' : null, // model resolves full path
            'port'              => $this->port ?: null,
            'transport'         => $transport,
            'dns'               => $this->dns ?: null,
            'enable_ipv6'       => (bool) $this->enableIPv6,
            'enable_logging'    => (bool) $this->enableLogging,
            'enable_proxy'      => (bool) $this->enableProxy,
            'header1'           => (bool) $this->header1,
            'header2'           => (bool) $this->header2,
            'deployment_status' => 'queued',
            'deployment_log'    => '',
            'status'            => 'pending',
        ];

        // 3) Optional columns – only set if the DB actually has them
        $maybe = [
            'provider'           => $this->nullIfEmpty($this->provider),
            'region'             => $this->nullIfEmpty($this->region),
            'country_code'       => $this->country_code ? strtoupper($this->country_code) : null,
            'city'               => $this->nullIfEmpty($this->city),
            'enabled'            => (bool) $this->enabled,
            'ipv6_enabled'       => is_null($this->ipv6_enabled) ? null : (bool) $this->ipv6_enabled,
            'mtu'                => $this->mtu ? (int) $this->mtu : null,
            'api_endpoint'       => $this->nullIfEmpty($this->api_endpoint),
            'api_token'          => $this->nullIfEmpty($this->api_token),
            'monitoring_enabled' => (bool) $this->monitoring_enabled,
            'health_check_cmd'   => $this->nullIfEmpty($this->health_check_cmd),
            'install_branch'     => $this->install_branch ?: 'stable',
            'max_clients'        => $this->max_clients ? (int) $this->max_clients : null,
            'rate_limit_mbps'    => $this->rate_limit_mbps ? (int) $this->rate_limit_mbps : null,
            'allow_split_tunnel' => (bool) $this->allow_split_tunnel,
            'ovpn_cipher'        => $this->nullIfEmpty($this->ovpn_cipher),
            'ovpn_compression'   => $this->nullIfEmpty($this->ovpn_compression),
            'wg_public_key'      => $this->nullIfEmpty($this->wg_public_key),
            'wg_private_key'     => $this->nullIfEmpty($this->wg_private_key),
            'notes'              => $this->nullIfEmpty($this->notes),
            'is_deploying'       => false,
        ];

        if (Schema::hasColumn('vpn_servers', 'tags')) {
            $maybe['tags'] = $this->normalizeTags($this->tags);
        }

        if (!empty($this->statusOverride) && Schema::hasColumn('vpn_servers', 'status')) {
            $maybe['status'] = $this->statusOverride;
        }

        foreach ($maybe as $column => $value) {
            if (Schema::hasColumn('vpn_servers', $column)) {
                $data[$column] = $value;
            }
        }

        // 4) Warn if SSH key path (legacy) is missing
        $legacyKeyPath = storage_path('app/ssh_keys/id_rsa');
        if ($this->sshType === 'key' && !file_exists($legacyKeyPath)) {
            Log::warning("⚠️ SSH key path missing: {$legacyKeyPath}");
            session()->flash(
                'error',
                'SSH key file not found (storage/app/ssh_keys/id_rsa). You can still save, but deployment may fail.'
            );
        }

        Log::info('Creating VpnServer with payload', [
            'name'       => $data['name'],
            'ip_address' => $data['ip_address'],
            'protocol'   => $data['protocol'],
            'country'    => $data['country_code'] ?? null,
            'city'       => $data['city'] ?? null,
        ]);

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
        if (!$raw) {
            return null;
        }

        // Accept "uk, london  , edge" or "uk london edge"
        $clean = collect(preg_split('/[,\s]+/', trim($raw)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $clean ?: null;
    }

    private function nullIfEmpty($value)
    {
        $value = is_string($value) ? trim($value) : $value;
        return $value === '' ? null : $value;
    }
}