<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AIO VPN – Core Control Plane Settings
    |--------------------------------------------------------------------------
    |
    | This section defines how your apps and backend see the "control plane":
    | the panel / API endpoints that must always stay reachable, even when the
    | VPN tunnel is up.
    |
    */

    // Public IP of the panel / API host.
    // Used to inject `route <ip> 255.255.255.255 net_gateway` into .ovpn configs.
    'control_plane_ip' => env('AIO_CONTROL_PLANE_IP', '94.237.52.172'),

    // Primary hostname for the control plane (for reference / logging / clients).
    'control_plane_host' => env('AIO_CONTROL_PLANE_HOST', 'panel.aiovpn.co.uk'),

    // Base URL that mobile/TV apps should talk to for API calls.
    'api_base_url' => env('AIO_API_BASE_URL', 'https://panel.aiovpn.co.uk'),

    /*
    |--------------------------------------------------------------------------
    | Mobile / TV Client Behaviour
    |--------------------------------------------------------------------------
    |
    | These flags describe how clients are expected to behave. They don’t
    | directly change Laravel logic, but they document and can inform helpers
    | and responses.
    |
    */

    // Whether clients should auto-connect on app launch (default suggestion).
    'default_auto_connect' => (bool) env('AIO_DEFAULT_AUTO_CONNECT', false),

    // Whether clients should enable kill switch by default (OpenVPN side).
    'default_kill_switch' => (bool) env('AIO_DEFAULT_KILL_SWITCH', false),

    // Default OpenVPN profile variant to serve if the client doesn’t specify one.
    // Allowed values: "unified", "udp", "stealth"
    'default_ovpn_variant' => env('AIO_DEFAULT_OVPN_VARIANT', 'unified'),

    /*
    |--------------------------------------------------------------------------
    | OpenVPN / WireGuard Policy Hints
    |--------------------------------------------------------------------------
    |
    | High-level rules for how configs should be built. You already enforce most
    | of this in VpnConfigBuilder, but centralising here keeps it consistent.
    |
    */

    'openvpn' => [
        // Whether to inject "persist-tun" and "block-outside-dns" when kill switch
        // is enabled on the client.
        'inject_kill_switch_directives' => (bool) env('AIO_OVPN_INJECT_KILLSWITCH', true),

        // Whether to always add "explicit-exit-notify 3" for UDP configs if missing.
        'ensure_explicit_exit_notify' => (bool) env('AIO_OVPN_EXPLICIT_EXIT_NOTIFY', true),
    ],

    'wireguard' => [
        // Whether WireGuard is officially supported for this deployment.
        'enabled' => (bool) env('AIO_WG_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry / MGMT Push (from VPN nodes)
    |--------------------------------------------------------------------------
    |
    | Settings related to the mgmt snapshots your agents push in to keep
    | online/offline status and live sessions accurate.
    |
    */

    // How long (in seconds) a user can be absent from mgmt snapshots before
    // being marked offline – mirror DeployEventController::OFFLINE_GRACE ideally.
    'mgmt_offline_grace' => (int) env('AIO_MGMT_OFFLINE_GRACE', 300),

    /*
    |--------------------------------------------------------------------------
    | Misc / Reserved for Future Use
    |--------------------------------------------------------------------------
    */

    // For future multi-region / multi-brand logic.
    'brand' => [
        'name' => env('AIO_BRAND_NAME', 'AIO VPN'),
    ],
];