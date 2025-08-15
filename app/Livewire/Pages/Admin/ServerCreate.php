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
        Log::info('🛠️ Server creation triggered', ['ip' => $this->ip]);

        // Extra rule when using password auth
        if ($this->sshType === 'password') {
            $this->validate(['sshPassword' => 'required|string']);
        }

        // Build base data (fields that you *do* have today)
        $data = [
            'name'               => $this->name,
            'ip_address'         => $this->ip,
            'protocol'           => strtolower($this->protocol),   // db stores lowercase
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
            'deployment_status'  => 'queued',   // existing enum has queued|running|success|failed|succeeded
            'deployment_log'     => '',
            'status'             => 'pending',  // your table has a non-nullable status
            // if you added a boolean is_deploying later, we’ll conditionally add it below
        ];

        // ===== Conditionally add future columns (safe on old schema) =====
        $maybe = [
            'provider'           => $this->provider,
            'region'             => $this->region,
            'country_code'       => $this->country_code,
            'city'               => $this->city,
            'enabled'            => (bool) $this->enabled,
            'ipv6_enabled'       => $this->ipv6_enabled, // if you rename later
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
            // alias: if your migration adds is_deploying
            'is_deploying'       => false,
        ];

        // If tags column exists, store JSON array
        if (Schema::hasColumn('vpn_servers', 'tags')) {
            $maybe['tags'] = $this->normalizeTags($this->tags);
        }

        // If you want to override initial status and the column exists
        if (!empty($this->statusOverride) && Schema::hasColumn('vpn_servers', 'status')) {
            $maybe['status'] = $this->statusOverride;
        }

        // Filter $maybe to only existing columns to avoid SQL errors
        foreach ($maybe as $column => $value) {
            if (Schema::hasColumn('vpn_servers', $column)) {
                $data[$column] = $value;
            }
        }

        // Warn if SSH key file is missing (non-blocking)
        if ($data['ssh_key'] && !file_exists($data['ssh_key'])) {
            Log::warning("⚠️ SSH key path missing: {$data['ssh_key']}");
            session()->flash('error', 'SSH key file not found (storage/app/ssh_keys/id_rsa). You can still save, but deployment may fail.');
        }

        $server = VpnServer::create($data);

        // Dispatch deployment job if column exists OR just always (job reads model anyway)
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