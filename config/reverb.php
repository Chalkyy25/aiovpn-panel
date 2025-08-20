<?php

return [
    'default' => env('REVERB_SERVER', 'reverb'),

    server {
    listen 443 ssl http2;
    server_name reverb.aiovpn.co.uk;

    ssl_certificate     /etc/letsencrypt/live/reverb.aiovpn.co.uk/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/reverb.aiovpn.co.uk/privkey.pem;

    location /app/ {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        proxy_read_timeout 60;
        proxy_connect_timeout 60;
        proxy_send_timeout 60;

        chunked_transfer_encoding off;
    }

    location / {
        return 404;
    }
}

    'apps' => [
        [
            'app_id' => env('REVERB_APP_ID', '1'),
            'key'    => env('REVERB_APP_KEY', 'localkey'),
            'secret' => env('REVERB_APP_SECRET', 'localsecret'),
            'options' => [
                'host'   => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
                'port'   => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'allowed_origins' => ['*'],
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_message_size' => 10000,
        ],
    ],
];