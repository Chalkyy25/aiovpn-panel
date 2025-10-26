#!/usr/bin/env bash
# ╭──────────────────────────────────────────────────────────────────────────╮
# │  A I O V P N — WG-first Deploy (WireGuard + OpenVPN+Stealth + DNS)       │
# │  Idempotent • Hardened • Ubuntu 22.04/24.04                              │
# ╰──────────────────────────────────────────────────────────────────────────╯
set -euo pipefail

### ===== Required env =====
: "${PANEL_URL:?set PANEL_URL, e.g. https://panel.aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

### ===== Behaviour toggles =====
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"              # 1=notify panel, 0=quiet
STATUS_PUSH_INTERVAL="${STATUS_PUSH_INTERVAL:-5s}"   # OpenVPN status push interval
ENABLE_PRIVATE_DNS="${ENABLE_PRIVATE_DNS:-1}"        # 1=Unbound bound to WG VPN IP

# Stealth/OpenVPN extras
ENABLE_TCP_STEALTH="${ENABLE_TCP_STEALTH:-1}"        # 1=add parallel TCP/443 instance
TCP_PORT="${TCP_PORT:-443}"
TCP_SUBNET="${TCP_SUBNET:-10.8.100.0/24}"
OVPN_ENDPOINT_HOST="${OVPN_ENDPOINT_HOST:-}"         # Defaults to WG endpoint if empty

### ===== Network/VPN defaults =====
# WireGuard (primary)
WG_SUBNET="${WG_SUBNET:-10.66.66.0/24}"
WG_SRV_IP="${WG_SRV_IP:-10.66.66.1/24}"   # server address on wg0
WG_PORT="${WG_PORT:-51820}"
WG_ENDPOINT_HOST="${WG_ENDPOINT_HOST:-}"   # auto-detect if empty

# OpenVPN (fallback)
OVPN_SUBNET="${OVPN_SUBNET:-10.8.0.0/24}"
OVPN_SRV_IP="${OVPN_SRV_IP:-10.8.0.1}"
OVPN_PORT="${OVPN_PORT:-1194}"
OVPN_PROTO="${OVPN_PROTO:-udp}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
STATUS_PATH="${STATUS_PATH:-/run/openvpn/server.status}"

# DNS given to clients (overridden by private DNS when ENABLE_PRIVATE_DNS=1)
DNS1="${DNS1:-1.1.1.1}"
DNS2="${DNS2:-8.8.8.8}"

# Legacy OVPN user/pass store (panel will mirror if provided)
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 18)}"

### ===== Logging =====
LOG_FILE="/root/vpn-deploy.log"
mkdir -p "$(dirname "$LOG_FILE")"
trap '' PIPE
exec > >(tee -i "$LOG_FILE" || true)
exec 2>&1
echo -e "\n================= START $(date -Is) ================="

### ===== Helpers =====
json_escape(){ sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
json_kv(){ printf '"%s":"%s"' "$1" "$(printf '%s' "$2" | json_escape)"; }
curl_auth(){ curl --retry 3 --retry-delay 2 -fsS -H "Authorization: Bearer $PANEL_TOKEN" "$@"; }
panel(){    # panel <METHOD> <PATH> [--json '{...}'] [--file /path]
  local method="$1"; shift
  local path="$1";   shift
  local url="${PANEL_URL%/}${path}"
  [[ "$PANEL_CALLBACKS" = "1" ]] || { [[ "${1-}" == "--file" ]] || true; return 0; }
  if [[ "${1-}" == "--file" ]]; then curl_auth -X "$method" -F "file=@$2" "$url" || true; return; fi
  if [[ "${1-}" == "--json" ]]; then curl_auth -X "$method" -H "Content-Type: application/json" -d "$2" "$url" || true; return; fi
  curl_auth -X "$method" "$url" || true
}
announce(){ panel POST "/api/servers/$SERVER_ID/deploy/events" --json \
  "{ $(json_kv status "$1"), $(json_kv message "$2") }"; }
logchunk(){ panel POST "/api/servers/$SERVER_ID/deploy/logs" --json \
  "{ $(json_kv line "$1") }" >/dev/null; }
fail(){ announce failed "$1"; exit 1; }
trap 'rc=$?; [[ $rc -eq 0 ]] || announce "failed" "Deployment failed (exit=$rc)"; exit $rc' ERR
announce running "Starting WG-first deployment"

### ===== System checks =====
[[ $EUID -eq 0 ]] || fail "Run as root"
command -v apt-get >/dev/null || fail "Debian/Ubuntu apt required"

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
apt_try(){ local t=3 i=1; until "$@"; do (( i>=t )) && return 1; sleep $((i*2)); ((i++)); done; }

preflight() {
  local ok=1
  for p in \
    "/api/servers/$SERVER_ID/deploy/events" \
    "/api/servers/$SERVER_ID/deploy/logs" \
    "/api/servers/$SERVER_ID/deploy/facts" \
    "/api/servers/$SERVER_ID/authfile" \
    "/api/servers/$SERVER_ID/provision/update" \
    "/api/servers/$SERVER_ID/events"
  do
    code=$(curl -s -o /dev/null -w '%{http_code}' \
      -H "Authorization: Bearer $PANEL_TOKEN" \
      -X OPTIONS "${PANEL_URL%/}${p}" || true)
    [[ "$code" =~ ^20[0-9]$|^405$ ]] || { echo "Preflight $p -> $code"; ok=0; }
  done
  [[ $ok -eq 1 ]] || { echo "Panel API preflight failed"; exit 1; }
}
preflight

### ===== Base packages =====
logchunk "Install/verify packages"
apt_try apt-get update -y
apt_try apt-get install -y \
  wireguard iproute2 iptables-persistent \
  openvpn easy-rsa \
  unbound dnsutils \
  curl ca-certificates jq python3 netcat-openbsd htop

### ===== Facts =====
DEF_IFACE="$(ip -4 route show default | awk '/default/ {print $5; exit}')" ; DEF_IFACE="${DEF_IFACE:-eth0}"

### ===== Enable IP forwarding (quiet) + tiny socket buffs =====
logchunk "Enable IPv4 forwarding & tune sockets"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-aiovpn.conf
# optional snappiness (safe defaults)
echo 'net.core.rmem_max=2500000' >> /etc/sysctl.d/99-aiovpn.conf
echo 'net.core.wmem_max=2500000' >> /etc/sysctl.d/99-aiovpn.conf
sysctl --system >/dev/null

### ===== Discover/confirm WG endpoint =====
if [[ -z "$WG_ENDPOINT_HOST" ]]; then
  logchunk "Detecting public IP for WG endpoint"
  WG_ENDPOINT_HOST="$(curl -4s https://api.ipify.org || true)"
  [[ -n "$WG_ENDPOINT_HOST" ]] || WG_ENDPOINT_HOST="$(curl -4s https://ifconfig.co || true)"
  [[ -n "$WG_ENDPOINT_HOST" ]] || fail "Could not detect public IPv4; set WG_ENDPOINT_HOST"
fi
logchunk "WG endpoint = ${WG_ENDPOINT_HOST}:${WG_PORT}"
# Use WG endpoint as OpenVPN endpoint if not explicitly set
if [[ -z "${OVPN_ENDPOINT_HOST}" ]]; then
  OVPN_ENDPOINT_HOST="${WG_ENDPOINT_HOST}"
fi

### ===== WireGuard (primary) =====
logchunk "Configure WireGuard (wg0)"
install -d -m 0700 /etc/wireguard

# Ensure kernel module exists/loaded
modprobe wireguard 2>/dev/null || true
if ! lsmod | grep -q '^wireguard'; then
  logchunk "WireGuard module missing; installing linux-modules-extra-$(uname -r)"
  apt_try apt-get install -y "linux-modules-extra-$(uname -r)" || true
  modprobe wireguard 2>/dev/null || true
fi

# Keys (idempotent)
if [[ ! -f /etc/wireguard/server_private_key ]]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
fi
WG_PRIV="$(cat /etc/wireguard/server_private_key)"
WG_PUB="$(cat /etc/wireguard/server_public_key)"

# wg0.conf (server)
cat >/etc/wireguard/wg0.conf <<WG
# === AIOVPN • WireGuard (Primary) ===
[Interface]
PrivateKey = $WG_PRIV
Address = $WG_SRV_IP
ListenPort = $WG_PORT
SaveConfig = true

# NAT out to internet
PostUp   = iptables -t nat -C POSTROUTING -o ${DEF_IFACE} -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o ${DEF_IFACE} -j MASQUERADE
PostDown = iptables -t nat -D POSTROUTING -o ${DEF_IFACE} -j MASQUERADE 2>/dev/null || true

# Allow forwarding (kernel)
PostUp   = sysctl -w net.ipv4.ip_forward=1
WG

chmod 600 /etc/wireguard/wg0.conf
systemctl daemon-reload
systemctl enable wg-quick@wg0
systemctl stop wg-quick@wg0 2>/dev/null || true
systemctl start wg-quick@wg0 || systemctl restart wg-quick@wg0

systemctl --no-pager --full status wg-quick@wg0 2>&1 | sed -e 's/"/\"/g' | while IFS= read -r L; do logchunk "$L"; done

WG_DNS_IP="$(printf '%s\n' "$WG_SRV_IP" | cut -d/ -f1)"
ok_iface=0
for i in {1..30}; do
  # Check if wg0 interface exists and has the expected IP
  if ip -4 addr show dev wg0 2>/dev/null | grep -q " ${WG_DNS_IP}/"; then 
    ok_iface=1
    logchunk "wg0 interface verified with IP ${WG_DNS_IP}"
    break
  fi
  # Also check if interface exists at all
  if ip link show wg0 2>/dev/null >/dev/null; then
    logchunk "wg0 interface exists, waiting for IP assignment... (attempt $i/30)"
  else
    logchunk "wg0 interface not found, waiting... (attempt $i/30)"
  fi
  sleep 0.5
done
if [ "$ok_iface" -ne 1 ]; then
  logchunk "wg0 did not come up with ${WG_DNS_IP}. Current interface status:"
  ip -4 addr show dev wg0 2>/dev/null || logchunk "wg0 interface not found"
  logchunk "Dumping systemd journal:"
  journalctl -u wg-quick@wg0 --no-pager -n 100 2>&1 | sed -e 's/"/\\"/g' | while IFS= read -r L; do logchunk "$L"; done
  fail "WireGuard (wg0) failed to start; check wg0.conf or kernel module"
fi

# Open WG firewall (udp)
iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT
iptables-save >/etc/iptables/rules.v4 || true

# Push WG facts to panel
panel POST "/api/servers/$SERVER_ID/provision/update" --json \
  "{ $(json_kv wg_endpoint_host "$WG_ENDPOINT_HOST"), \"wg_port\": $WG_PORT, $(json_kv wg_subnet "$WG_SUBNET"), $(json_kv wg_public_key "$WG_PUB") }" >/dev/null || true

### ===== AIO Private DNS (bound to WG IP) =====
install_private_dns() {
  [[ "$ENABLE_PRIVATE_DNS" = "1" ]] || { echo "[DNS] Private DNS disabled"; return 0; }

  local bind_ip; bind_ip="$(printf '%s\n' "$WG_SRV_IP" | cut -d/ -f1)"
  echo "[DNS] Setting up AIOVPN Resolver on ${bind_ip} (${WG_SUBNET})"

  install -d -m 0755 /etc/unbound/unbound.conf.d
  install -d -m 0755 -o unbound -g unbound /run/unbound /var/lib/unbound || true

  rm -f /var/lib/unbound/root.key || true
  unbound-anchor -a /var/lib/unbound/root.key || true
  chown unbound:unbound /var/lib/unbound/root.key || true

  # --- Unbound recursive resolver bound to WG IP (privacy-first) ---
  cat >/etc/unbound/unbound.conf.d/aio.conf <<EOF
server:
  # === AIOVPN Resolver (Recursive) ===
  identity: "AIOVPN Resolver"
  version: "secure"

  username: "unbound"
  directory: "/etc/unbound"
  chroot: ""
  pidfile: "/run/unbound/unbound.pid"

  # Bind only to WireGuard IP
  interface: ${bind_ip}
  so-reuseport: yes
  port: 53
  do-ip4: yes
  do-ip6: no
  do-udp: yes
  do-tcp: yes

  # Outbound recursion over the default route
  outgoing-interface: 0.0.0.0
  do-not-query-localhost: no

  # Hardening + performance
  hide-identity: yes
  hide-version: yes
  qname-minimisation: yes
  harden-glue: yes
  harden-dnssec-stripped: yes
  harden-algo-downgrade: yes
  aggressive-nsec: yes
  prefetch: yes
  prefetch-key: yes
  rrset-roundrobin: yes

  # Cache tuning
  cache-min-ttl: 60
  cache-max-ttl: 86400
  neg-cache-size: 8m
  msg-cache-size: 64m
  rrset-cache-size: 128m
  outgoing-range: 512
  num-threads: 2

  # Quiet logging
  logfile: ""
  verbosity: 0

  # Allow both VPN subnets
  access-control: ${WG_SUBNET} allow        # WireGuard
  access-control: ${OVPN_SUBNET} allow      # OpenVPN
  access-control: 0.0.0.0/0 refuse

# Use DNS-over-TLS upstreams for privacy
forward-zone:
  name: "."
  forward-tls-upstream: yes
  forward-addr: 1.1.1.1@853
  forward-addr: 1.0.0.1@853
  forward-addr: 9.9.9.9@853
  forward-addr: 149.112.112.112@853
EOF

  unbound-checkconf

  # Ensure Unbound only starts after wg0 has the IP
  mkdir -p /etc/systemd/system/unbound.service.d
  cat >/etc/systemd/system/unbound.service.d/override.conf <<EOF
[Unit]
After=wg-quick@wg0.service network-online.target
Wants=wg-quick@wg0.service network-online.target

[Service]
ExecStartPre=/bin/sh -c 'for i in \$(seq 1 20); do ip -4 addr show wg0 | grep -q "${bind_ip}/" && exit 0; sleep 0.5; done; echo "wg0 not ready"; exit 1'
EOF

  systemctl daemon-reload
  systemctl enable unbound
  systemctl stop unbound 2>/dev/null || true
  systemctl start unbound
  systemctl is-active --quiet unbound || fail "unbound failed to start (wg0 DNS bind)"

  # Allow DNS from VPN interfaces
  iptables -C INPUT -i wg0 -p udp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i wg0 -p udp --dport 53 -j ACCEPT
  iptables -C INPUT -i wg0 -p tcp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i wg0 -p tcp --dport 53 -j ACCEPT
  # Allow OVPN clients on tun0 to reach DNS bound to WG IP
  iptables -C INPUT -i tun0 -d ${bind_ip} -p udp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i tun0 -d ${bind_ip} -p udp --dport 53 -j ACCEPT
  iptables -C INPUT -i tun0 -d ${bind_ip} -p tcp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i tun0 -d ${bind_ip} -p tcp --dport 53 -j ACCEPT

  iptables-save >/etc/iptables/rules.v4 || true
  echo "[DNS] AIOVPN Resolver ready at ${bind_ip}:53"
}
install_private_dns

### ===== OpenVPN (fallback + stealth) =====
logchunk "Configure OpenVPN (fallback & stealth)"
install -d -m 0755 /etc/openvpn/easy-rsa
cp -a /usr/share/easy-rsa/* /etc/openvpn/easy-rsa 2>/dev/null || true
pushd /etc/openvpn/easy-rsa >/dev/null
export EASYRSA_BATCH=1
if [[ ! -d pki ]]; then
  ./easyrsa init-pki
  ./easyrsa build-ca nopass
  ./easyrsa gen-req server nopass
  ./easyrsa sign-req server server
  ./easyrsa gen-dh
  openvpn --genkey --secret /etc/openvpn/ta.key
  install -m 0644 pki/ca.crt             /etc/openvpn/ca.crt
  install -m 0644 pki/issued/server.crt  /etc/openvpn/server.crt
  install -m 0600 pki/private/server.key /etc/openvpn/server.key
  install -m 0644 pki/dh.pem             /etc/openvpn/dh.pem
fi
popd >/dev/null

# Auth store
install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
else
  umask 077; printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
fi
rm -f /tmp/panel-auth

# Password checker
cat >/etc/openvpn/auth/checkpsw.sh <<'SH'
#!/bin/sh
set -eu
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
CRED_FILE="$1"
TS="$(date '+%F %T')"
[ -r "$PASSFILE" ] || { echo "$TS: Cannot read $PASSFILE" >>"$LOG_FILE"; exit 1; }
[ -r "$CRED_FILE" ] || { echo "$TS: Cannot read $CRED_FILE" >>"$LOG_FILE"; exit 1; }
CREDENTIALS="$(tr -d '\r' < "$CRED_FILE" | tr '\n\t' '  ' | awk '{$1=$1;print}')"
USER="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f1)"
PASS="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f2-)"
[ -n "$USER" ] && [ -n "$PASS" ] || { echo "$TS: Empty user/pass" >>"$LOG_FILE"; exit 1; }
GOOD="$(awk -v u="$USER" '$1==u { $1=""; sub(/^[ \t]+/,""); print; exit }' "$PASSFILE")"
[ -n "$GOOD" ] && [ "$PASS" = "$GOOD" ] && { echo "$TS: OK $USER" >>"$LOG_FILE"; exit 0; }
echo "$TS: FAIL $USER" >>"$LOG_FILE"; exit 1
SH
chmod 0755 /etc/openvpn/auth/checkpsw.sh

# server.conf (UDP Fallback) — switched to tls-crypt
cat >/etc/openvpn/server.conf <<CONF
# === AIOVPN • OpenVPN (Fallback, UDP) ===
port $OVPN_PORT
proto $OVPN_PROTO
dev tun

ca ca.crt
cert server.crt
key server.key
dh dh.pem
tls-crypt ta.key
tls-version-min 1.2

data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
auth SHA256

topology subnet
server ${OVPN_SUBNET%/*} 255.255.255.0
ifconfig-pool-persist ipp.txt

keepalive 10 60
reneg-sec 0

persist-key
persist-tun

status $STATUS_PATH 10
status-version 3
verb 3
management $MGMT_HOST $MGMT_PORT
script-security 3

verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file

# Default DNS (will be replaced by 10.66.66.1 if ENABLE_PRIVATE_DNS=1)
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS $DNS1"
push "dhcp-option DNS $DNS2"

# Throughput
sndbuf 0
rcvbuf 0
push "sndbuf 0"
push "rcvbuf 0"

tun-mtu 1500
mssfix 1450

explicit-exit-notify 3
CONF

# If private DNS is enabled, push WG DNS IP to OVPN clients
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  OVPN_CONF="/etc/openvpn/server.conf"
  sed -i '/^push "dhcp-option DNS /d' "$OVPN_CONF"
  sed -i '/^push "dhcp-option DOMAIN-ROUTE /d' "$OVPN_CONF"
  echo "push \"dhcp-option DNS ${WG_DNS_IP}\"" >> "$OVPN_CONF"
  echo "push \"dhcp-option DOMAIN-ROUTE .\""   >> "$OVPN_CONF"
fi

# NAT + OVPN firewall (UDP)
iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE
iptables -C INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT
iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
iptables-save >/etc/iptables/rules.v4 || true

systemctl enable openvpn@server
systemctl restart openvpn@server
systemctl is-active --quiet openvpn@server || fail "OpenVPN (UDP) failed to start"

# Optional: parallel TCP/443 stealth instance
if [[ "${ENABLE_TCP_STEALTH}" = "1" ]]; then
  logchunk "Configuring OpenVPN TCP stealth on :${TCP_PORT}"
  TCP_CONF="/etc/openvpn/server-tcp.conf"
  [[ -s /etc/openvpn/ta.key ]] || { openvpn --genkey --secret /etc/openvpn/ta.key; chmod 600 /etc/openvpn/ta.key; }

  if [[ ! -f "$TCP_CONF" ]]; then
    cat >"$TCP_CONF" <<CONF
# === AIOVPN • OpenVPN (Stealth, TCP 443) ===
port ${TCP_PORT}
proto tcp
dev tun

ca /etc/openvpn/ca.crt
cert /etc/openvpn/server.crt
key /etc/openvpn/server.key
dh /etc/openvpn/dh.pem
tls-crypt /etc/openvpn/ta.key
tls-version-min 1.2

data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
auth SHA256

topology subnet
server ${TCP_SUBNET%/*} 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp.txt

keepalive 10 60
reneg-sec 0

persist-key
persist-tun

status /var/log/openvpn-status-tcp.log 10
status-version 3
verb 3
script-security 3

verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
CONF
    chmod 0644 "$TCP_CONF"
  fi

  # Firewall/NAT for TCP 443 and TCP subnet
  iptables -C INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT 2>/dev/null || iptables -A INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT
  iptables -t nat -C POSTROUTING -s "${TCP_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || \
    iptables -t nat -A POSTROUTING -s "${TCP_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE
  iptables-save >/etc/iptables/rules.v4 || true

  # Start TCP instance (handle both unit naming styles)
  systemctl enable openvpn@server-tcp 2>/dev/null || true
  systemctl enable openvpn-server@server-tcp 2>/dev/null || true
  systemctl restart openvpn@server-tcp 2>/dev/null || systemctl restart openvpn-server@server-tcp || true
fi

### ===== Quick DNS sanity =====
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  dig @"$WG_DNS_IP" example.com +short || echo "[DNS] dig check failed (verify wg0 up and client reachability)"
fi

### ===== OpenVPN status push (v3) =====
logchunk "Install OVPN status push agent"
cat >/usr/local/bin/ovpn-status-push.sh <<'AGENT'
#!/usr/bin/env bash
set -euo pipefail
PANEL_URL="${PANEL_URL:?}"; SERVER_ID="${SERVER_ID:?}"; PANEL_TOKEN="${PANEL_TOKEN:?}"
STATUS_PATH="${STATUS_PATH:-/run/openvpn/server.status}"
JSON_PAYLOAD="$(/usr/bin/env python3 - "$STATUS_PATH" <<'PY'
import sys,csv,json,datetime
p=sys.argv[1]
clients,virt={},{}
hdr_CL, hdr_RT = {}, {}
try:
  with open(p,'r',newline='') as f:
    s=f.read(4096); f.seek(0)
    d=',' if s.count(',')>=s.count('\t') else '\t'
    r=csv.reader(f,delimiter=d)
    for row in r:
      if not row: continue
      tag=row[0]
      if tag=='HEADER' and len(row)>2:
        if row[1]=='CLIENT_LIST': hdr_CL={n:i for i,n in enumerate(row)}
        elif row[1]=='ROUTING_TABLE': hdr_RT={n:i for i,n in enumerate(row)}
        continue
      if tag=='CLIENT_LIST':
        def col(h,default=''):
          i=hdr_CL.get(h); return row[i] if i is not None and i<len(row) else default
        cn=col('Common Name') or col('Username') or ''
        if not cn: continue
        real=col('Real Address') or None
        real_ip=real.split(':')[0] if real else None
        def to_int(x): 
          try: return int(x)
          except: return None
        rx=to_int(col('Bytes Received','0') or 0) or 0
        tx=to_int(col('Bytes Sent','0') or 0) or 0
        ts=col('Connected Since (time_t)','') or ''
        cid=col('Client ID','') or None
        clients[cn]={"username":cn,"client_ip":real_ip,"virtual_ip":None,
                     "bytes_received":rx,"bytes_sent":tx,
                     "connected_at": to_int(ts),
                     "client_id": to_int(cid)}
      elif tag=='ROUTING_TABLE':
        def col(h,default=''):
          i=hdr_RT.get(h); return row[i] if i is not None and i<len(row) else default
        virt[col('Common Name','')]=col('Virtual Address') or None
except FileNotFoundError:
  pass
for cn,ip in virt.items():
  if cn in clients and ip: clients[cn]["virtual_ip"]=ip
u=list(clients.values())
print(json.dumps({"status":"mgmt","ts":datetime.datetime.utcnow().isoformat()+"Z",
                  "clients":len(u),"cn_list":",".join([x["username"] for x in u]),
                  "users":u}, separators=(",",":")))
PY
)"
curl -sS -X POST \
  -H "Authorization: Bearer ${PANEL_TOKEN}" \
  -H "Content-Type: application/json" \
  --data-raw "${JSON_PAYLOAD}" \
  "${PANEL_URL%/}/api/servers/${SERVER_ID}/events" >/dev/null 2>&1 || true
AGENT
chmod 0755 /usr/local/bin/ovpn-status-push.sh

cat >/etc/systemd/system/ovpn-status-push.service <<'SVC'
[Unit]
Description=Post OpenVPN status (v3) to panel
After=openvpn@server.service
[Service]
Type=oneshot
EnvironmentFile=-/etc/default/ovpn-status-push
ExecStart=/usr/local/bin/ovpn-status-push.sh
SVC

cat >/etc/systemd/system/ovpn-status-push.timer <<'TIM'
[Unit]
Description=Post OpenVPN status (v3) to panel
[Timer]
OnBootSec=5s
OnUnitActiveSec=5s
AccuracySec=1s
Unit=ovpn-status-push.service
[Install]
WantedBy=timers.target
TIM

cat >/etc/default/ovpn-status-push <<ENV
PANEL_URL="$PANEL_URL"
PANEL_TOKEN="$PANEL_TOKEN"
SERVER_ID="$SERVER_ID"
STATUS_PATH="$STATUS_PATH"
ENV

sed -i "s/OnUnitActiveSec=.*/OnUnitActiveSec=${STATUS_PUSH_INTERVAL}/" /etc/systemd/system/ovpn-status-push.timer || true
systemctl daemon-reload
# ensure only the timer runs the oneshot
systemctl disable --now ovpn-status-push.service 2>/dev/null || true
systemctl enable --now ovpn-status-push.timer
systemctl status --no-pager --full ovpn-status-push.timer >/dev/null 2>&1 || true

### ===== Mirror OVPN auth back to panel (optional) =====
panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true

### ===== Emit client profiles (tls-crypt) =====
GEN_DIR="/root/clients"
mkdir -p "$GEN_DIR"
CA_CONTENT="$(cat /etc/openvpn/ca.crt)"
TA_CONTENT="$(cat /etc/openvpn/ta.key)"

# UDP profile (stealthier control via tls-crypt)
cat > "${GEN_DIR}/aio-udp${OVPN_PORT}.ovpn" <<OVPN
client
dev tun
proto ${OVPN_PROTO}
remote ${OVPN_ENDPOINT_HOST} ${OVPN_PORT}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3
<tls-crypt>
${TA_CONTENT}
</tls-crypt>
<ca>
${CA_CONTENT}
</ca>
OVPN

# TCP stealth profile (if enabled)
if [[ "${ENABLE_TCP_STEALTH}" = "1" ]]; then
cat > "${GEN_DIR}/aio-tcp${TCP_PORT}.ovpn" <<OVPN
client
dev tun
proto tcp
remote ${OVPN_ENDPOINT_HOST} ${TCP_PORT}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3
<tls-crypt>
${TA_CONTENT}
</tls-crypt>
<ca>
${CA_CONTENT}
</ca>
OVPN
fi

### ===== Final facts =====
panel POST "/api/servers/$SERVER_ID/deploy/facts" --json \
"{ $(json_kv iface "$DEF_IFACE"),
   $(json_kv proto "wireguard+openvpn-stealth"),
   \"mgmt_port\": $MGMT_PORT,
   \"wg_port\": $WG_PORT,
   $(json_kv wg_public_key "$WG_PUB"),
   $(json_kv wg_endpoint_host "$WG_ENDPOINT_HOST"),
   $(json_kv ovpn_endpoint_host "$OVPN_ENDPOINT_HOST"),
   \"ip_forward\": 1 }" >/dev/null || true

announce succeeded "WG-first + Stealth deployment complete"
echo "✅ Done $(date -Is)"