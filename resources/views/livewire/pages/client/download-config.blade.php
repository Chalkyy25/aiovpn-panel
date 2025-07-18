client
dev tun
proto udp
remote {{ $ip }} {{ $port }}
resolv-retry infinite
nobind
persist-key
persist-tun
explicit-exit-notify
remote-cert-tls server
auth SHA256
cipher AES-256-CBC
data-ciphers AES-256-CBC
tls-version-min 1.2
reneg-sec 0
verb 3
auth-user-pass

<ca>
{{ $ca }}
</ca>

<tls-auth>
{{ $ta }}
</tls-auth>
key-direction 1
