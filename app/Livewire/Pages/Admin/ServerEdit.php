<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ServerEdit extends Component
{
    public VpnServer $vpnServer;

    // Basics
    public string $name;
    public string $ip_address;
    public string $protocol;              // openvpn|wireguard
    public string $status;                // online|offline|pending

    // Identity/meta
    public ?string $provider = null;
    public ?string $region = null;
    public ?string $country_code = null;
    public ?string $city = null;
    public bool $enabled = true;
    public ?string $tags = null;          // CSV in UI, array in DB

    // Networking
    public int $ssh_port = 22;
    public bool $ipv6_enabled = false;
    public ?string $dns = null;           // CSV or single
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

    public function mount(VpnServer $vpnServer): void
    {
        $this->vpnServer = $vpnServer;

        // Required
        $this->name       = (string) $vpnServer->name;
        $this->ip_address = (string) $vpnServer->ip_address;
        $this->protocol   = $vpnServer->protocol ?: 'openvpn';
        $this->status     = $vpnServer->status   ?: 'pending';

        // Identity / meta
        $this->provider     = $vpnServer->provider;
        $this->region       = $vpnServer->region;
        $this->country_code = $vpnServer->country_code
            ? strtoupper($vpnServer->country_code)
            : null;
        $this->city         = $vpnServer->city;
        $this->enabled      = (bool) ($vpnServer->enabled ?? true);

        // tags is cast to array in the model – turn into CSV for UI
        $tags = $vpnServer->tags ?? [];
        $this->tags = $tags
            ? implode(', ', array_filter((array) $tags))
            : null;

        // Networking
        $this->ssh_port     = (int) ($vpnServer->ssh_port ?? 22);
        $this->ipv6_enabled = (bool) ($vpnServer->ipv6_enabled ?? false);
        $this->dns          = $vpnServer->dns;
        $this->mtu          = $vpnServer->mtu;

        // API / agent
        $this->api_endpoint = $vpnServer->api_endpoint;
        $this->api_token    = $vpnServer->api_token;

        // Monitoring / maintenance
        $this->monitoring_enabled = (bool) ($vpnServer->monitoring_enabled ?? true);
        $this->health_check_cmd   = $vpnServer->health_check_cmd;
        $this->install_branch     = $vpnServer->install_branch;

        // Limits
        $this->max_clients        = $vpnServer->max_clients;
        $this->rate_limit_mbps    = $vpnServer->rate_limit_mbps;
        $this->allow_split_tunnel = (bool) ($vpnServer->allow_split_tunnel ?? false);

        // OpenVPN
        $this->ovpn_cipher      = $vpnServer->ovpn_cipher;
        $this->ovpn_compression = $vpnServer->ovpn_compression;

        // WireGuard
        $this->wg_public_key  = $vpnServer->wg_public_key;
        $this->wg_private_key = $vpnServer->wg_private_key;

        // Notes
        $this->notes = $vpnServer->notes;
    }

    public function rules(): array
    {
        return [
            // Required
            'name'       => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'string', 'max:255'], // can be host or IP
            'protocol'   => ['required', Rule::in(['openvpn', 'wireguard'])],
            'status'     => ['required', Rule::in(['online', 'offline', 'pending'])],

            // Identity / meta
            'provider'     => ['nullable', 'string', 'max:100'],
            'region'       => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city'         => ['nullable', 'string', 'max:80'],
            'enabled'      => ['boolean'],
            'tags'         => ['nullable', 'string', 'max:255'], // CSV in UI

            // Networking
            'ssh_port'     => ['required', 'integer', 'between:1,65535'],
            'ipv6_enabled' => ['boolean'],
            'dns'          => ['nullable', 'string', 'max:255'],
            'mtu'          => ['nullable', 'integer', 'between:576,9000'],

            // API
            'api_endpoint' => ['nullable', 'string', 'max:255'],
            'api_token'    => ['nullable', 'string', 'max:255'],

            // Monitoring
            'monitoring_enabled' => ['boolean'],
            'health_check_cmd'   => ['nullable', 'string', 'max:255'],
            'install_branch'     => ['nullable', 'string', 'max:64'],

            // Limits
            'max_clients'       => ['nullable', 'integer', 'between:1,65000'],
            'rate_limit_mbps'   => ['nullable', 'integer', 'between:1,10000'],
            'allow_split_tunnel'=> ['boolean'],

            // OpenVPN
            'ovpn_cipher'       => ['nullable', 'string', 'max:64'],
            'ovpn_compression'  => ['nullable', 'string', 'max:32'],

            // WireGuard
            'wg_public_key'     => ['nullable', 'string'],
            'wg_private_key'    => ['nullable', 'string'],

            // Notes
            'notes'             => ['nullable', 'string', 'max:500'],
        ];
    }

    public function save()
    {
        $this->validate();

        // Normalise / trim
        $protocol     = strtolower($this->protocol);
        $country_code = $this->country_code
            ? strtoupper(trim($this->country_code))
            : null;

        $this->vpnServer->update([
            // Required
            'name'       => trim($this->name),
            'ip_address' => trim($this->ip_address),
            'protocol'   => $protocol,
            'status'     => $this->status,

            // Identity / meta
            'provider'     => $this->nullIfEmpty($this->provider),
            'region'       => $this->nullIfEmpty($this->region),
            'country_code' => $country_code,
            'city'         => $this->nullIfEmpty($this->city),
            'enabled'      => $this->enabled,
            'tags'         => $this->normalizeTags($this->tags),

            // Networking
            'ssh_port'     => $this->ssh_port,
            'ipv6_enabled' => $this->ipv6_enabled,
            'dns'          => $this->nullIfEmpty($this->dns),
            'mtu'          => $this->mtu ?: null,

            // API
            'api_endpoint' => $this->nullIfEmpty($this->api_endpoint),
            'api_token'    => $this->nullIfEmpty($this->api_token),

            // Monitoring
            'monitoring_enabled' => $this->monitoring_enabled,
            'health_check_cmd'   => $this->nullIfEmpty($this->health_check_cmd),
            'install_branch'     => $this->nullIfEmpty($this->install_branch),

            // Limits
            'max_clients'       => $this->max_clients ?: null,
            'rate_limit_mbps'   => $this->rate_limit_mbps ?: null,
            'allow_split_tunnel'=> $this->allow_split_tunnel,

            // OpenVPN
            'ovpn_cipher'       => $this->nullIfEmpty($this->ovpn_cipher),
            'ovpn_compression'  => $this->nullIfEmpty($this->ovpn_compression),

            // WireGuard
            'wg_public_key'     => $this->nullIfEmpty($this->wg_public_key),
            'wg_private_key'    => $this->nullIfEmpty($this->wg_private_key),

            // Notes
            'notes'             => $this->nullIfEmpty($this->notes),
        ]);

        session()->flash('status-message', 'Server updated successfully.');
        return redirect()->route('admin.servers.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.server-edit')
            ->layoutData(['heading' => 'Edit Server']);
    }

    private function nullIfEmpty($value)
    {
        $value = is_string($value) ? trim($value) : $value;
        return $value === '' ? null : $value;
    }

    private function normalizeTags(?string $raw): ?array
    {
        if (!$raw) {
            return null;
        }

        $clean = collect(preg_split('/[,\s]+/', trim($raw)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $clean ?: null;
    }
}