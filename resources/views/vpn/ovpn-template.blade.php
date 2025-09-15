client
dev tun
proto udp
remote {{ $server->ip_address }} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth-user-pass
cipher AES-256-CBC
verb 3

<ca>
{{ $server->ca_cert }}
</ca>

<tls-auth>
{{ $server->tls_key }}
</tls-auth>
