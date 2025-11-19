#!/usr/bin/env bash
# ╭──────────────────────────────────────────────────────────────────────────╮
# │  A I O V P N — WG-first Deploy (WireGuard + OpenVPN+Stealth + DNS)      │
# │  Idempotent • Hardened • Ubuntu 22.04/24.04                             │
# │  CHANGE: Unified WG + OpenVPN status push via mgmt & wg dump           │
# ╰──────────────────────────────────────────────────────────────────────────╯
set -euo pipefail

### ===== Required env =====
: "${PANEL_URL:?set PANEL_URL, e.g. https://panel.aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

### ===== Behaviour toggles =====
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"              # 1=notify panel, 0=quiet
STATUS_PUSH_INTERVAL="${STATUS_PUSH_INTERVAL:-2s}"   # status push interval (timer)
ENABLE_PRIVATE_DNS="${ENABLE_PRIVATE_DNS:-1}"        # 1=Unbound bound to WG VPN IP
ENABLE_TCP_STEALTH="${ENABLE_TCP_STEALTH:-0}"        # 0=UDP only, 1=add TCP/443

### ===== Ports/Subnets =====
TCP_PORT="${TCP_PORT:-443}"
TCP_SUBNET="${TCP_SUBNET:-10.8.100.0/24}"
OVPN_ENDPOINT_HOST="${OVPN_ENDPOINT_HOST:-}"         # Defaults to WG endpoint if empty

### ===== Network/VPN defaults =====
# WireGuard (primary)
WG_SUBNET="${WG_SUBNET:-10.66.66.0/24}"
WG_SRV_IP="${WG_SRV_IP:-10.66.66.1/24}"
WG_PORT="${WG_PORT:-51820}"
WG_ENDPOINT_HOST="${WG_ENDPOINT_HOST:-}"

# OpenVPN (fallback)
OVPN_SUBNET="${OVPN_SUBNET:-10.8.0.0/24}"
OVPN_SRV_IP="${OVPN_SRV_IP:-10.8.0.1}"
OVPN_PORT="${OVPN_PORT:-1194}"
OVPN_PROTO="${OVPN_PROTO:-udp}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
MGMT_TCP_PORT="${MGMT_TCP_PORT:-7506}"

# === Status paths ===
STATUS_UDP_PATH="${STATUS_UDP_PATH:-/var/log/openvpn-status-udp.log}"
STATUS_TCP_PATH="${STATUS_TCP_PATH:-/var/log/openvpn-status-tcp.log}"

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
panel(){
  local method="$1"; shift
  local path="$1"; shift
  local url="${PANEL_URL%/}${path}"
  [[ "$PANEL_CALLBACKS" = "1" ]] || { [[ "${1-}" == "--file" ]] || true; return 0; }
  if [[ "${1-}" == "--file" ]]; then curl_auth -X "$method" -F "file=@$2" "$url" || true; return; fi
  if [[ "${1-}" == "--json" ]]; then curl_auth -X "$method" -H "Content-Type: application/json" -d "$2" "$url" || true; return; fi
  curl_auth -X "$method" "$url" || true
}
announce(){ panel POST "/api/servers/$SERVER_ID/deploy/events" --json "{ $(json_kv status "$1"), $(json_kv message "$2") }"; }
logchunk(){ panel POST "/api/servers/$SERVER_ID/deploy/logs" --json "{ $(json_kv line "$1") }" >/dev/null; }
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
    code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $PANEL_TOKEN" -X OPTIONS "${PANEL_URL%/}${p}" || true)
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

### ===== Enable IP forwarding & tune sockets =====
logchunk "Enable IPv4 forwarding & tune sockets"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-aiovpn.conf
echo 'net.core.rmem_max=2500000' >> /etc/sysctl.d/99-aiovpn.conf
echo 'net.core.wmem_max=2500000' >> /etc/sysctl.d/99-aiovpn.conf
sysctl --system >/dev/null

### ===== Detect endpoints =====
if [[ -z "$WG_ENDPOINT_HOST" ]]; then
  logchunk "Detecting public IP for WG endpoint"
  WG_ENDPOINT_HOST="$(curl -4s https://api.ipify.org || true)"
  [[ -n "$WG_ENDPOINT_HOST" ]] || WG_ENDPOINT_HOST="$(curl -4s https://ifconfig.co || true)"
  [[ -n "$WG_ENDPOINT_HOST" ]] || fail "Could not detect public IPv4; set WG_ENDPOINT_HOST"
fi
logchunk "WG endpoint = ${WG_ENDPOINT_HOST}:${WG_PORT}"
[[ -n "${OVPN_ENDPOINT_HOST}" ]] || OVPN_ENDPOINT_HOST="${WG_ENDPOINT_HOST}"

### ===== WireGuard (primary) =====
logchunk "Configure WireGuard (wg0)"
install -d -m 0700 /etc/wireguard
modprobe wireguard 2>/dev/null || true
if ! lsmod | grep -q '^wireguard'; then
  logchunk "Installing linux-modules-extra for wireguard"
  apt_try apt-get install -y "linux-modules-extra-$(uname -r)" || true
  modprobe wireguard 2>/dev/null || true
fi

if [[ ! -f /etc/wireguard/server_private_key ]]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
fi
WG_PRIV="$(cat /etc/wireguard/server_private_key)"
WG_PUB="$(cat /etc/wireguard/server_public_key)"

cat >/etc/wireguard/wg0.conf <<WG
# === AIOVPN • WireGuard (Primary) ===
[Interface]
PrivateKey = $WG_PRIV
Address = $WG_SRV_IP
ListenPort = $WG_PORT
SaveConfig = true
WG
chmod 600 /etc/wireguard/wg0.conf
systemctl daemon-reload
systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0
systemctl --no-pager --full status wg-quick@wg0 2>&1 | sed -e 's/"/\"/g' | while IFS= read -r L; do logchunk "$L"; done

WG_DNS_IP="$(printf '%s\n' "$WG_SRV_IP" | cut -d/ -f1)"
ok_iface=0
for i in {1..30}; do
  if ip -4 addr show dev wg0 2>/dev/null | grep -q " ${WG_DNS_IP}/"; then ok_iface=1; logchunk "wg0 up at ${WG_DNS_IP}"; break; fi
  sleep 0.5
done
[[ $ok_iface -eq 1 ]] || fail "WireGuard (wg0) failed to start"

iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT
iptables-save >/etc/iptables/rules.v4 || true

panel POST "/api/servers/$SERVER_ID/provision/update" --json \
  "{ $(json_kv wg_endpoint_host "$WG_ENDPOINT_HOST"), \"wg_port\": $WG_PORT, $(json_kv wg_subnet "$WG_SUBNET"), $(json_kv wg_public_key "$WG_PUB") }" >/dev/null || true

### ===== AIO Private DNS =====
install_private_dns() {
  [[ "$ENABLE_PRIVATE_DNS" = "1" ]] || { echo "[DNS] Private DNS disabled"; return 0; }
  local bind_ip; bind_ip="${WG_DNS_IP}"
  echo "[DNS] Setting up Unbound on ${bind_ip}"

  install -d -m 0755 /etc/unbound/unbound.conf.d
  install -d -m 0755 -o unbound -g unbound /run/unbound /var/lib/unbound || true
  rm -f /var/lib/unbound/root.key || true
  unbound-anchor -a /var/lib/unbound/root.key || true
  chown unbound:unbound /var/lib/unbound/root.key || true

  cat >/etc/unbound/unbound.conf.d/aio.conf <<EOF
server:
  identity: "AIOVPN Resolver"
  version: "secure"
  username: "unbound"
  directory: "/etc/unbound"
  chroot: ""
  pidfile: "/run/unbound/unbound.pid"
  interface: ${bind_ip}
  so-reuseport: yes
  port: 53
  do-ip4: yes
  do-ip6: no
  do-udp: yes
  do-tcp: yes
  outgoing-interface: 0.0.0.0
  do-not-query-localhost: no
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
  cache-min-ttl: 60
  cache-max-ttl: 86400
  neg-cache-size: 8m
  msg-cache-size: 64m
  rrset-cache-size: 128m
  outgoing-range: 512
  num-threads: 2
  logfile: ""
  verbosity: 0
  access-control: ${WG_SUBNET} allow
  access-control: ${OVPN_SUBNET} allow
  access-control: 0.0.0.0/0 refuse
forward-zone:
  name: "."
  forward-tls-upstream: yes
  forward-addr: 1.1.1.1@853
  forward-addr: 1.0.0.1@853
  forward-addr: 9.9.9.9@853
  forward-addr: 149.112.112.112@853
EOF

  mkdir -p /etc/systemd/system/unbound.service.d
  cat >/etc/systemd/system/unbound.service.d/override.conf <<EOF
[Unit]
After=wg-quick@wg0.service network-online.target
Wants=wg-quick@wg0.service network-online.target
[Service]
ExecStartPre=/bin/sh -c 'for i in \$(seq 1 20); do ip -4 addr show wg0 | grep -q "${bind_ip}/" && exit 0; sleep 0.5; done; exit 1'
EOF

  systemctl daemon-reload
  systemctl enable unbound
  systemctl restart unbound
  systemctl is-active --quiet unbound || fail "unbound failed to start"

  iptables -C INPUT -i wg0 -p udp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i wg0 -p udp --dport 53 -j ACCEPT
  iptables -C INPUT -i wg0 -p tcp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i wg0 -p tcp --dport 53 -j ACCEPT
  iptables -C INPUT -i tun0 -d ${bind_ip} -p udp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i tun0 -d ${bind_ip} -p udp --dport 53 -j ACCEPT
  iptables -C INPUT -i tun0 -d ${bind_ip} -p tcp --dport 53 -j ACCEPT 2>/dev/null || iptables -A INPUT -i tun0 -d ${bind_ip} -p tcp --dport 53 -j ACCEPT
  iptables-save >/etc/iptables/rules.v4 || true
  echo "[DNS] Resolver ready at ${bind_ip}:53"
}
install_private_dns

### ===== OpenVPN (fallback + stealth) =====
logchunk "Configure OpenVPN (fallback & stealth)"

# Stop and disable legacy openvpn@ services to avoid port conflicts
systemctl stop openvpn@server 2>/dev/null || true
systemctl disable openvpn@server 2>/dev/null || true
systemctl stop openvpn@server-tcp 2>/dev/null || true
systemctl disable openvpn@server-tcp 2>/dev/null || true

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

install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
else
  umask 077; printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
fi; rm -f /tmp/panel-auth

cat >/etc/openvpn/auth/checkpsw.sh <<'SH'
#!/bin/sh
set -eu
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
CRED_FILE="$1"; TS="$(date '+%F %T')"
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

install -d -m 0755 /etc/openvpn/server
install -d -m 0755 /etc/openvpn/client

# === UDP server.conf ===
cat >/etc/openvpn/server/server.conf <<CONF
# === AIOVPN • OpenVPN (Fallback, UDP) ===
port $OVPN_PORT
proto $OVPN_PROTO
dev tun
ca /etc/openvpn/ca.crt
cert /etc/openvpn/server.crt
key /etc/openvpn/server.key
dh /etc/openvpn/dh.pem
tls-crypt /etc/openvpn/ta.key
tls-version-min 1.2
data-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
data-ciphers-fallback AES-128-GCM
ncp-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
cipher AES-128-GCM
auth SHA256
topology subnet
server ${OVPN_SUBNET%/*} 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp-udp.txt
keepalive 3 10
reneg-sec 0
persist-key
persist-tun
status ${STATUS_UDP_PATH} 1
status-version 3
verb 3
management ${MGMT_HOST} ${MGMT_PORT}
script-security 3
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS ${DNS1}"
push "dhcp-option DNS ${DNS2}"
sndbuf 0
rcvbuf 0
push "sndbuf 0"
push "rcvbuf 0"
tun-mtu 1500
mssfix 1450
explicit-exit-notify 3
CONF

# Private DNS for UDP clients
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  sed -i '/^push "dhcp-option DNS /d' /etc/openvpn/server/server.conf
  sed -i '/^push "dhcp-option DOMAIN-ROUTE /d' /etc/openvpn/server/server.conf
  echo "push \"dhcp-option DNS ${WG_DNS_IP}\""  >> /etc/openvpn/server/server.conf
  echo "push \"dhcp-option DOMAIN-ROUTE .\""    >> /etc/openvpn/server/server.conf
fi

# OpenVPN UDP port (INPUT)
iptables -C INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT 2>/dev/null || \
iptables -A INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT

# Ensure status files exist
install -o root -g root -m 644 /dev/null "${STATUS_UDP_PATH}"
install -o root -g root -m 644 /dev/null "${STATUS_TCP_PATH}" 2>/dev/null || true

# Start UDP OpenVPN
systemctl enable openvpn-server@server
systemctl restart openvpn-server@server
systemctl is-active --quiet openvpn-server@server || fail "OpenVPN (UDP) failed to start"

# === TCP stealth (optional) ===
if [[ "${ENABLE_TCP_STEALTH}" = "1" ]]; then
  logchunk "Configuring OpenVPN TCP stealth on :${TCP_PORT}"
  TCP_CONF="/etc/openvpn/server/server-tcp.conf"
  [[ -s /etc/openvpn/ta.key ]] || { openvpn --genkey --secret /etc/openvpn/ta.key; chmod 600 /etc/openvpn/ta.key; }
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
ncp-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
cipher AES-128-GCM
auth SHA256
topology subnet
server ${TCP_SUBNET%/*} 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp-tcp.txt
keepalive 3 10
reneg-sec 0
persist-key
persist-tun
status ${STATUS_TCP_PATH} 1
status-version 3
verb 3
management ${MGMT_HOST} ${MGMT_TCP_PORT}
script-security 3
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS ${DNS1}"
push "dhcp-option DNS ${DNS2}"
sndbuf 0
rcvbuf 0
push "sndbuf 0"
push "rcvbuf 0"
tun-mtu 1500
mssfix 1450
CONF
  chmod 0644 "$TCP_CONF"

  if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
    sed -i '/^push "dhcp-option DNS /d' "$TCP_CONF"
    sed -i '/^push "dhcp-option DOMAIN-ROUTE /d' "$TCP_CONF"
    echo "push \"dhcp-option DNS ${WG_DNS_IP}\"" >> "$TCP_CONF"
    echo "push \"dhcp-option DOMAIN-ROUTE .\""   >> "$TCP_CONF"
  fi

  # OpenVPN TCP stealth port (INPUT)
  iptables -C INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT 2>/dev/null || \
  iptables -A INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT

  systemctl enable openvpn-server@server-tcp
  systemctl restart openvpn-server@server-tcp
  systemctl is-active --quiet openvpn-server@server-tcp || logchunk "WARNING: TCP stealth service failed to start"
fi

### ===== Firewall: reset + strict VPN rules =====
logchunk "Resetting iptables FORWARD/NAT for VPN"

# 1) Flush FORWARD and set default DROP
iptables -F FORWARD
iptables -P FORWARD DROP

# 2) Flush NAT POSTROUTING and rebuild VPN-only MASQUERADE
iptables -t nat -F POSTROUTING

# NAT for WireGuard subnet
iptables -t nat -A POSTROUTING -s "$WG_SUBNET" -o "$DEF_IFACE" -j MASQUERADE

# NAT for OpenVPN UDP subnet
iptables -t nat -A POSTROUTING -s "${OVPN_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE

# NAT for OpenVPN TCP stealth subnet (if enabled)
if [[ "$ENABLE_TCP_STEALTH" = "1" ]]; then
  iptables -t nat -A POSTROUTING -s "${TCP_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE
fi

# 3) Strict FORWARD rules: only VPN <-> WAN

# WireGuard: wg0 -> WAN and return
iptables -A FORWARD -i wg0 -o "$DEF_IFACE" -j ACCEPT
iptables -A FORWARD -i "$DEF_IFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT

# OpenVPN: tun0 -> WAN and return
iptables -A FORWARD -i tun0 -o "$DEF_IFACE" -j ACCEPT
iptables -A FORWARD -i "$DEF_IFACE" -o tun0 -m state --state RELATED,ESTABLISHED -j ACCEPT

# 4) Clamp MSS for all forwarded TCP to avoid MTU issues
iptables -t mangle -F FORWARD
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu

# 5) Persist
iptables-save >/etc/iptables/rules.v4 || true

### ===== Quick DNS sanity =====
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  dig @"$WG_DNS_IP" example.com +short || echo "[DNS] dig check failed (verify wg0 up and client reachability)"
fi

### ===== Unified WG + OpenVPN status push agent =====
cat >/usr/local/bin/ovpn-mgmt-push.sh <<'AGENT'
#!/usr/bin/env bash
set -euo pipefail

# Load and export config for Python
if [ -f /etc/default/ovpn-status-push ]; then
  set -a
  . /etc/default/ovpn-status-push
  set +a
fi

: "${PANEL_URL:?PANEL_URL missing}"
: "${PANEL_TOKEN:?PANEL_TOKEN missing}"
: "${SERVER_ID:?SERVER_ID missing}"

post() {
  curl -fsS -X POST \
    -H "Authorization: Bearer ${PANEL_TOKEN}" \
    -H "Content-Type: application/json" \
    --data-raw "$(cat)" \
    "${PANEL_URL%/}/api/servers/${SERVER_ID}/events" >/dev/null || true
}

json="$(
python3 <<'PY'
import os, sys, csv, json, datetime, subprocess, time

PANEL_URL    = os.environ["PANEL_URL"]
PANEL_TOKEN  = os.environ["PANEL_TOKEN"]
SERVER_ID    = os.environ["SERVER_ID"]
MGMT_PORT    = os.environ.get("MGMT_PORT", "7505")
MGMT_TCP_PORT= os.environ.get("MGMT_TCP_PORT", "7506")

def parse_ovpn_status(txt, proto_hint=None):
    clients, virt = {}, {}
    hCL, hRT = {}, {}

    for row in csv.reader(txt.splitlines()):
        if not row:
            continue
        tag = row[0]

        if tag == "HEADER" and len(row) > 2:
            if row[1] == "CLIENT_LIST":
                hCL = {n: i for i, n in enumerate(row)}
            elif row[1] == "ROUTING_TABLE":
                hRT = {n: i for i, n in enumerate(row)}
            continue

        if tag == "CLIENT_LIST":
            def col(h, d=""):
                i = hCL.get(h)
                return row[i] if i is not None and i < len(row) else d

            cn = col("Common Name") or col("Username")
            if not cn:
                continue

            real = col("Real Address") or ""
            real_ip = real.split(":")[0] if real else None

            def toint(x):
                try:
                    return int(x)
                except Exception:
                    return 0

            clients[cn] = {
                "username": cn,
                "client_ip": real_ip,
                "virtual_ip": None,
                "bytes_received": toint(col("Bytes Received", "0")),
                "bytes_sent": toint(col("Bytes Sent", "0")),
                "connected_at": toint(col("Connected Since (time_t)", "0")),
                "proto": proto_hint or "openvpn",
            }

        elif tag == "ROUTING_TABLE":
            def col(h, d=""):
                i = hRT.get(h)
                return row[i] if i is not None and i < len(row) else d
            virt[col("Common Name", "")] = col("Virtual Address") or None

    for cn, ip in virt.items():
        if cn in clients and ip:
            # strip mask if present
            clients[cn]["virtual_ip"] = ip.split("/", 1)[0]

    return list(clients.values())

def collect_ovpn():
    chunks = []
    for port, hint in ((MGMT_PORT, "openvpn-udp"), (MGMT_TCP_PORT, "openvpn-tcp")):
        try:
            out = subprocess.check_output(
                ["bash", "-lc", f'printf "status 3\\nquit\\n" | nc -w 2 127.0.0.1 {port} || true'],
                text=True,
            )
        except Exception:
            continue

        if "CLIENT_LIST" in out:
            chunks.append((out, hint))

    clients = []
    for txt, hint in chunks:
        clients.extend(parse_ovpn_status(txt, proto_hint=hint))
    return clients

def collect_wg():
    try:
        dump = subprocess.check_output(["wg", "show", "wg0", "dump"], text=True)
    except Exception:
        return []

    lines = [l.strip() for l in dump.splitlines() if l.strip()]
    if len(lines) <= 1:
        return []

    peers = []
    now = int(time.time())
    OFFLINE_IDLE = 300  # 5 minutes of no handshake = offline

    for line in lines[1:]:
        parts = line.split("\t")
        if len(parts) < 8:
            continue

        pub, _psk, endpoint, allowed, hs_raw, rx_raw, tx_raw, _keep = parts[:8]

        try:
            hs = int(hs_raw)
        except Exception:
            hs = 0

        # must have at least one handshake
        if hs <= 0:
            continue

        # drop only if truly idle for a long time
        if now - hs > OFFLINE_IDLE:
            continue

        client_ip = None
        if endpoint and endpoint != "(none)" and ":" in endpoint:
            client_ip = endpoint.rsplit(":", 1)[0]

        virt_ip = None
        if allowed and allowed not in ("", "(none)"):
            first = allowed.split(",")[0].strip()
            if first:
                virt_ip = first.split("/", 1)[0]

        def toint(x):
            try:
                return int(x)
            except Exception:
                return 0

        peers.append({
            "username": pub,
            "public_key": pub,
            "client_ip": client_ip,
            "virtual_ip": virt_ip,
            "bytes_received": toint(rx_raw),
            "bytes_sent": toint(tx_raw),
            "connected_at": hs,
            "proto": "wireguard",
        })

    return peers

ovpn_clients = collect_ovpn()
wg_clients   = collect_wg()
all_clients  = ovpn_clients + wg_clients

payload = {
    "status": "mgmt",
    "ts": datetime.datetime.utcnow().isoformat() + "Z",
    "clients": len(all_clients),
    "users": all_clients,
}

print(json.dumps(payload, separators=(",", ":")))
PY
)"

# if python failed or empty, bail quietly
if [ -z "${json:-}" ]; then
  exit 0
fi

# echo for manual debugging if run by hand
printf '%s\n' "$json"

# send to panel
printf '%s\n' "$json" | post
AGENT

chmod 0755 /usr/local/bin/ovpn-mgmt-push.sh

# Environment for status agent
cat >/etc/default/ovpn-status-push <<ENV
PANEL_URL="$PANEL_URL"
PANEL_TOKEN="$PANEL_TOKEN"
SERVER_ID="$SERVER_ID"
MGMT_PORT="${MGMT_PORT}"
MGMT_TCP_PORT="${MGMT_TCP_PORT}"
ENV

# Systemd service (oneshot)
cat >/etc/systemd/system/ovpn-mgmt-push.service <<'SVC'
[Unit]
Description=Push unified VPN (WG+OVPN) status to panel
After=network-online.target wg-quick@wg0.service openvpn-server@server.service openvpn-server@server-tcp.service
Wants=network-online.target
[Service]
Type=oneshot
EnvironmentFile=-/etc/default/ovpn-status-push
ExecStart=/usr/local/bin/ovpn-mgmt-push.sh
SVC

# Systemd timer (periodic)
cat >/etc/systemd/system/ovpn-mgmt-push.timer <<TIM
[Unit]
Description=Push unified VPN (WG+OVPN) status every ${STATUS_PUSH_INTERVAL}
[Timer]
OnBootSec=5s
OnUnitActiveSec=${STATUS_PUSH_INTERVAL}
AccuracySec=1s
Unit=ovpn-mgmt-push.service
[Install]
WantedBy=timers.target
TIM

# Remove any client-connect/disconnect hooks that might break auth
for conf in /etc/openvpn/server/server.conf /etc/openvpn/server/server-tcp.conf; do
  [[ -f "$conf" ]] || continue
  sed -i '/^client-connect\|^client-disconnect/d' "$conf"
done

systemctl daemon-reload
systemctl enable --now ovpn-mgmt-push.timer

### ===== Mirror OVPN auth back to panel (optional) =====
panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true

### ===== Emit client profiles =====
GEN_DIR="/root/clients"
mkdir -p "$GEN_DIR"
CA_CONTENT="$(cat /etc/openvpn/ca.crt)"
TA_CONTENT="$(cat /etc/openvpn/ta.key)"

# UDP profile
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
data-ciphers-fallback AES-128-GCM
pull-filter ignore "cipher"
<tls-crypt>
${TA_CONTENT}
</tls-crypt>
<ca>
${CA_CONTENT}
</ca>
OVPN

# TCP profile (if enabled)
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
connect-retry 3
connect-retry-max 5
connect-timeout 10
hand-window 30
data-ciphers-fallback AES-128-GCM
pull-filter ignore "cipher"
comp-lzo no
mute-replay-warnings
<tls-crypt>
${TA_CONTENT}
</tls-crypt>
<ca>
${CA_CONTENT}
</ca>
OVPN
fi

# Unified profile (TCP+UDP)
cat > "${GEN_DIR}/aio-unified.ovpn" <<OVPN
client
dev tun
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth SHA256
auth-user-pass
auth-nocache
verb 3
remote ${OVPN_ENDPOINT_HOST} ${TCP_PORT} tcp
remote ${OVPN_ENDPOINT_HOST} ${OVPN_PORT} ${OVPN_PROTO}
connect-retry 2
connect-retry-max 3
connect-timeout 8
hand-window 20
data-ciphers-fallback AES-128-GCM
pull-filter ignore "cipher"
comp-lzo no
mute-replay-warnings
<tls-crypt>
${TA_CONTENT}
</tls-crypt>
<ca>
${CA_CONTENT}
</ca>
OVPN

### ===== Final facts =====

# Decide what DNS to advertise to panel (private WG DNS if enabled)
DNS_FACT="$WG_DNS_IP"
if [[ "$ENABLE_PRIVATE_DNS" != "1" ]]; then
  DNS_FACT="$DNS1"
fi

panel POST "/api/servers/$SERVER_ID/deploy/facts" --json \
"{ $(json_kv iface "$DEF_IFACE"),
   \"vpn_port\": $OVPN_PORT,
   $(json_kv proto "wireguard+openvpn-stealth"),
   \"ip_forward\": 1,

   # Public endpoint + WG facts used by WireGuardConfigController
   $(json_kv public_ip "$WG_ENDPOINT_HOST"),
   $(json_kv wg_public_key "$WG_PUB"),
   \"wg_port\": $WG_PORT,
   $(json_kv wg_subnet "$WG_SUBNET"),
   $(json_kv dns "$DNS_FACT"),

   # Optional extra metadata (controller will ignore unknown keys)
   \"mgmt_port\": $MGMT_PORT,
   \"mgmt_tcp_port\": $MGMT_TCP_PORT,
   $(json_kv ovpn_endpoint_host "$OVPN_ENDPOINT_HOST"),
   \"tcp_stealth_enabled\": $([ "$ENABLE_TCP_STEALTH" = "1" ] && echo "true" || echo "false"),
   \"tcp_port\": $TCP_PORT,
   $(json_kv tcp_subnet "$TCP_SUBNET"),
   $(json_kv status_udp "$STATUS_UDP_PATH"),
   $(json_kv status_tcp "$STATUS_TCP_PATH")
 }" >/dev/null || true

announce succeeded "WG-first + Stealth deployment complete (unified WG+OVPN monitoring)"
echo "✅ Done $(date -Is)"