<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    
    'panel' => [
        'base'  => env('PANEL_BASE_URL', 'https://aiovpn.co.uk'),
        'token' => env('PANEL_TOKEN'),
    ],
    
    'wireguard' => [
    'resync_on_deploy' => env('WG_RESYNC_ON_DEPLOY', true),
],
    
    'vpn_nodes' => [
    'ssh_user' => env('VPNNODE_SSH_USER', 'root'),
    'ssh_key'  => env('VPNNODE_SSH_KEY',  '/root/.ssh/id_rsa'),
    'ssh_port' => env('VPNNODE_SSH_PORT', 22),
],


    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
