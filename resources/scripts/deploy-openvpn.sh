#!/usr/bin/env bash
# ╭──────────────────────────────────────────────────────────────────────────╮
# │  A I O V P N — WG-first Deploy (WireGuard + OpenVPN+Stealth + DNS)       │
# │  Idempotent • Hardened • Ubuntu 22.04/24.04                              │
# │  CHANGE: Consistent OpenVPN status paths in /var/log for UDP & TCP       │
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
ENABLE_TCP_STEALTH="${ENABLE_TCP_STEALTH:-1}"        # 1=add parallel TCP/443 instance

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

# === CONSISTENT STATUS PATHS (CHANGED) ===
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
panel(){ local method="$1"; shift; local path="$1"; shift; local url="${PANEL_URL%/}${path}";
  [[ "$PANEL_CALLBACKS" = "1" ]] || { [[ "${1-}" == "--file" ]] || true; return 0; }
  if [[ "${1-}" == "--file" ]]; then curl_auth -X "$method" -F "file=@$2" "$url" || true; return; fi
  if [[ "${1-}" == "--json" ]]; then curl_auth -X "$method" -H "Content-Type: application/json" -d "$2" "$url" || true; return; fi
  curl_auth -X "$method" "$url" || true; }
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
PostUp   = iptables -t nat -C POSTROUTING -o ${DEF_IFACE} -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o ${DEF_IFACE} -j MASQUERADE
PostDown = iptables -t nat -D POSTROUTING -o ${DEF_IFACE} -j MASQUERADE 2>/dev/null || true
PostUp   = sysctl -w net.ipv4.ip_forward=1
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

# === Ensure proper directory structure for systemd services ===
install -d -m 0755 /etc/openvpn/server
install -d -m 0755 /etc/openvpn/client

# === UDP server.conf (CHANGED: consistent status path) ===
cat >/etc/openvpn/server/server.conf <<CONF
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
ncp-ciphers AES-128-GCM:CHACHA20-POLY1305:AES-256-GCM
cipher AES-128-GCM
auth SHA256
topology subnet
server ${OVPN_SUBNET%/*} 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp-udp.txt
keepalive 10 60
reneg-sec 0
persist-key
persist-tun
status ${STATUS_UDP_PATH} 10
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

# Private DNS for UDP
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  sed -i '/^push "dhcp-option DNS /d' /etc/openvpn/server/server.conf
  sed -i '/^push "dhcp-option DOMAIN-ROUTE /d' /etc/openvpn/server/server.conf
  echo "push \"dhcp-option DNS ${WG_DNS_IP}\""  >> /etc/openvpn/server/server.conf
  echo "push \"dhcp-option DOMAIN-ROUTE .\""    >> /etc/openvpn/server/server.conf
fi

# Firewall
iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE
iptables -C INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p "$OVPN_PROTO" --dport "$OVPN_PORT" -j ACCEPT
iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
iptables-save >/etc/iptables/rules.v4 || true

# Ensure status files exist (CHANGED)
install -o root -g root -m 644 /dev/null "${STATUS_UDP_PATH}"
install -o root -g root -m 644 /dev/null "${STATUS_TCP_PATH}" 2>/dev/null || true

# Use openvpn-server@ (not openvpn@) for systemd service
systemctl enable openvpn-server@server
systemctl restart openvpn-server@server
systemctl is-active --quiet openvpn-server@server || fail "OpenVPN (UDP) failed to start"

# === TCP stealth (CHANGED: consistent status path + mgmt port) ===
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
keepalive 10 60
reneg-sec 0
persist-key
persist-tun
status ${STATUS_TCP_PATH} 10
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

  iptables -C INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT 2>/dev/null || iptables -A INPUT -p tcp --dport "${TCP_PORT}" -j ACCEPT
  iptables -t nat -C POSTROUTING -s "${TCP_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -s "${TCP_SUBNET%/*}/24" -o "$DEF_IFACE" -j MASQUERADE
  iptables-save >/etc/iptables/rules.v4 || true

  # Use openvpn-server@ for systemd (requires config in /etc/openvpn/server/)
  systemctl enable openvpn-server@server-tcp
  systemctl restart openvpn-server@server-tcp
  systemctl is-active --quiet openvpn-server@server-tcp || logchunk "WARNING: TCP stealth service failed to start"
fi

### ===== Quick DNS sanity =====
if [[ "$ENABLE_PRIVATE_DNS" = "1" ]]; then
  dig @"$WG_DNS_IP" example.com +short || echo "[DNS] dig check failed (verify wg0 up and client reachability)"
fi

### ===== OpenVPN status push agents (CHANGED defaults) =====
logchunk "Install OVPN status push agent(s)"
cat >/usr/local/bin/ovpn-status-push.sh <<'AGENT'
#!/usr/bin/env bash
set -euo pipefail
PANEL_URL="${PANEL_URL:?}"; SERVER_ID="${SERVER_ID:?}"; PANEL_TOKEN="${PANEL_TOKEN:?}"
STATUS_PATH="${STATUS_PATH:?}"
JSON_PAYLOAD="$(
/usr/bin/env python3 - <<'PY'
import sys,csv,json,datetime,os
p=os.environ.get("STATUS_PATH","")
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
        ts=to_int(col('Connected Since (time_t)','') or 0) or None
        cid=to_int(col('Client ID','') or 0) or None
        clients[cn]={"username":cn,"client_ip":real_ip,"virtual_ip":None,
                     "bytes_received":rx,"bytes_sent":tx,
                     "connected_at": ts,"client_id": cid}
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
                  "clients":len(u),"users":u,
                  "cn_list":",".join([x["username"] for x in u])}, separators=(",",":")))
PY
)"
curl -sS -X POST -H "Authorization: Bearer ${PANEL_TOKEN}" -H "Content-Type: application/json" \
  --data-raw "${JSON_PAYLOAD}" \
  "${PANEL_URL%/}/api/servers/${SERVER_ID}/events" >/dev/null 2>&1 || true
AGENT
chmod 0755 /usr/local/bin/ovpn-status-push.sh

# Default env (UDP path)
cat >/etc/default/ovpn-status-push <<ENV
PANEL_URL="$PANEL_URL"
PANEL_TOKEN="$PANEL_TOKEN"
SERVER_ID="$SERVER_ID"
STATUS_PATH="${STATUS_UDP_PATH}"
ENV

# UDP status push timer
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

# TCP status push timer with explicit path override
cat >/etc/systemd/system/ovpn-status-push-tcp.service <<SVC
[Unit]
Description=Post OpenVPN TCP status (v3) to panel
After=openvpn-server@server-tcp.service
[Service]
Type=oneshot
Environment="STATUS_PATH=${STATUS_TCP_PATH}"
EnvironmentFile=-/etc/default/ovpn-status-push
ExecStart=/usr/local/bin/ovpn-status-push.sh
SVC
cat >/etc/systemd/system/ovpn-status-push-tcp.timer <<'TIM'
[Unit]
Description=Post OpenVPN TCP status (v3) to panel
[Timer]
OnBootSec=7s
OnUnitActiveSec=5s
AccuracySec=1s
Unit=ovpn-status-push-tcp.service
[Install]
WantedBy=timers.target
TIM

# Apply interval override
sed -i "s/OnUnitActiveSec=.*/OnUnitActiveSec=${STATUS_PUSH_INTERVAL}/" /etc/systemd/system/ovpn-status-push.timer
sed -i "s/OnUnitActiveSec=.*/OnUnitActiveSec=${STATUS_PUSH_INTERVAL}/" /etc/systemd/system/ovpn-status-push-tcp.timer
systemctl daemon-reload
systemctl disable --now ovpn-status-push.service ovpn-status-push-tcp.service 2>/dev/null || true
systemctl enable --now ovpn-status-push.timer
[[ "${ENABLE_TCP_STEALTH}" = "1" ]] && systemctl enable --now ovpn-status-push-tcp.timer || true

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

# Unified profile
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
panel POST "/api/servers/$SERVER_ID/deploy/facts" --json \
"{ $(json_kv iface "$DEF_IFACE"),
   $(json_kv proto "wireguard+openvpn-stealth"),
   \"mgmt_port\": $MGMT_PORT,
   \"mgmt_tcp_port\": $MGMT_TCP_PORT,
   \"wg_port\": $WG_PORT,
   $(json_kv wg_public_key "$WG_PUB"),
   $(json_kv wg_endpoint_host "$WG_ENDPOINT_HOST"),
   $(json_kv ovpn_endpoint_host "$OVPN_ENDPOINT_HOST"),
   \"ovpn_udp_port\": $OVPN_PORT,
   \"tcp_stealth_enabled\": $([ "$ENABLE_TCP_STEALTH" = "1" ] && echo "true" || echo "false"),
   \"tcp_port\": $TCP_PORT,
   $(json_kv tcp_subnet "$TCP_SUBNET"),
   $(json_kv status_udp "$STATUS_UDP_PATH"),
   $(json_kv status_tcp "$STATUS_TCP_PATH"),
   \"ip_forward\": 1 }" >/dev/null || true

announce succeeded "WG-first + Stealth deployment complete (consistent status paths)"
echo "✅ Done $(date -Is)"