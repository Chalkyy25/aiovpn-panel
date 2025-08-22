<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Validation\Rule;

#[Layout('layouts.app')]
class ServerEdit extends Component
{
    public VpnServer $vpnServer;

    // Basics you already had
    public string $name;
    public string $ip_address;
    public string $protocol;              // openvpn|wireguard
    public string $status;                // online|offline|pending

    // New — identity/meta
    public ?string $provider = null;
    public ?string $region = null;
    public ?string $country_code = null;
    public ?string $city = null;
    public bool $enabled = true;
    public ?string $tags = null;          // CSV in UI (will JSON encode to DB)

    // Networking
    public int $ssh_port = 22;
    public bool $ipv6_enabled = false;
    public ?string $dns = null;           // CSV
    public ?int $mtu = null;

    // API / agent
    public ?string $api_endpoint = null;
    public ?string $api_token = null;

    // Monitoring / maintenance
    public bool $monitoring_enabled = true;
    public ?string $health_check_cmd = null;
    public ?string $install_branch = null;

    // Limits
    public ?int $max_clients = null;
    public ?int $rate_limit_mbps = null;
    public bool $allow_split_tunnel = false;

    // OpenVPN
    public ?string $ovpn_cipher = null;
    public ?string $ovpn_compression = null;

    // WireGuard
    public ?string $wg_public_key = null;
    public ?string $wg_private_key = null;

    // Notes
    public ?string $notes = null;

    public function mount(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;

        // Existing
        $this->name       = (string) $vpnServer->name;
        $this->ip_address = (string) $vpnServer->ip_address;
        $this->protocol   = $vpnServer->protocol ?: 'openvpn';
        $this->status     = $vpnServer->status   ?: 'pending';

        // New (provide safe defaults)
        $this->provider           = $vpnServer->provider;
        $this->region             = $vpnServer->region;
        $this->country_code       = $vpnServer->country_code;
        $this->city               = $vpnServer->city;
        $this->enabled            = (bool) ($vpnServer->enabled ?? true);
        $this->tags               = $vpnServer->tags ? implode(',', (array) json_decode($vpnServer->tags, true)) : null;

        $this->ssh_port           = (int) ($vpnServer->ssh_port ?? 22);
        $this->ipv6_enabled       = (bool) ($vpnServer->ipv6_enabled ?? false);
        $this->dns                = $vpnServer->dns;
        $this->mtu                = $vpnServer->mtu;

        $this->api_endpoint       = $vpnServer->api_endpoint;
        $this->api_token          = $vpnServer->api_token;

        $this->monitoring_enabled = (bool) ($vpnServer->monitoring_enabled ?? true);
        $this->health_check_cmd   = $vpnServer->health_check_cmd ?? null;
        $this->install_branch     = $vpnServer->install_branch ?? null;

        $this->max_clients        = $vpnServer->max_clients;
        $this->rate_limit_mbps    = $vpnServer->rate_limit_mbps;
        $this->allow_split_tunnel = (bool) ($vpnServer->allow_split_tunnel ?? false);

        $this->ovpn_cipher        = $vpnServer->ovpn_cipher;
        $this->ovpn_compression   = $vpnServer->ovpn_compression;

        $this->wg_public_key      = $vpnServer->wg_public_key;
        $this->wg_private_key     = $vpnServer->wg_private_key;

        $this->notes              = $vpnServer->notes;
    }

    public function rules(): array
    {
        return [
            // Required
            'name'       => ['required','string','max:255'],
            'ip_address' => ['required','string','max:255'], // can be host or IP
            'protocol'   => ['required', Rule::in(['openvpn','wireguard'])],
            'status'     => ['required', Rule::in(['online','offline','pending'])],

            // Identity / meta
            'provider'     => ['nullable','string','max:100'],
            'region'       => ['nullable','string','max:100'],
            'country_code' => ['nullable','string','size:2'],
            'city'         => ['nullable','string','max:80'],
            'enabled'      => ['boolean'],
            'tags'         => ['nullable','string','max:255'], // CSV

            // Networking
            'ssh_port'     => ['required','integer','between:1,65535'],
            'ipv6_enabled' => ['boolean'],
            'dns'          => ['nullable','string','max:255'],
            'mtu'          => ['nullable','integer','between:576,9000'],

            // API
            'api_endpoint' => ['nullable','string','max:255'],
            'api_token'    => ['nullable','string','max:255'],

            // Monitoring
            'monitoring_enabled' => ['boolean'],
            'health_check_cmd'   => ['nullable','string','max:255'],
            'install_branch'     => ['nullable','string','max:64'],

            // Limits
            'max_clients'       => ['nullable','integer','between:1,65000'],
            'rate_limit_mbps'   => ['nullable','integer','between:1,10000'],
            'allow_split_tunnel'=> ['boolean'],

            // OpenVPN
            'ovpn_cipher'       => ['nullable','string','max:64'],
            'ovpn_compression'  => ['nullable','string','max:32'],

            // WireGuard
            'wg_public_key'     => ['nullable','string'],
            'wg_private_key'    => ['nullable','string'],

            // Notes
            'notes'             => ['nullable','string','max:500'],
        ];
    }

    public function save()
    {
        $this->validate();

        $this->vpnServer->update([
            // Required
            'name'       => $this->name,
            'ip_address' => $this->ip_address,
            'protocol'   => $this->protocol,
            'status'     => $this->status,

            // Identity / meta
            'provider'     => $this->provider,
            'region'       => $this->region,
            'country_code' => $this->country_code ? strtoupper($this->country_code) : null,
            'city'         => $this->city,
            'enabled'      => $this->enabled,
            'tags'         => $this->tags
                ? json_encode(collect(explode(',', $this->tags))->map(fn($t)=>trim($t))->filter()->values()->all())
                : null,

            // Networking
            'ssh_port'     => $this->ssh_port,
            'ipv6_enabled' => $this->ipv6_enabled,
            'dns'          => $this->dns,
            'mtu'          => $this->mtu,

            // API
            'api_endpoint' => $this->api_endpoint,
            'api_token'    => $this->api_token,

            // Monitoring
            'monitoring_enabled' => $this->monitoring_enabled,
            'health_check_cmd'   => $this->health_check_cmd,
            'install_branch'     => $this->install_branch,

            // Limits
            'max_clients'       => $this->max_clients,
            'rate_limit_mbps'   => $this->rate_limit_mbps,
            'allow_split_tunnel'=> $this->allow_split_tunnel,

            // OpenVPN
            'ovpn_cipher'       => $this->ovpn_cipher,
            'ovpn_compression'  => $this->ovpn_compression,

            // WireGuard
            'wg_public_key'     => $this->wg_public_key,
            'wg_private_key'    => $this->wg_private_key,

            // Notes
            'notes'             => $this->notes,
        ]);

        session()->flash('status-message', 'Server updated successfully.');
        return redirect()->route('admin.servers.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.server-edit')
            ->layoutData(['heading' => 'Edit Server']);
    }
}