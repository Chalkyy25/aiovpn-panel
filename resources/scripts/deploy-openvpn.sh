#!/usr/bin/env bash
# AIOVPN deploy (OpenVPN + optional WireGuard) — tuned for throughput
# Idempotent. Safe to re-run. Requires Ubuntu 22.04/24.04.

set -euo pipefail

### ===== Required env =====
: "${PANEL_URL:?set PANEL_URL, e.g. https://aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

### ===== Optional toggles =====
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"          # 1=notify panel, 0=quiet
STATUS_PATH="${STATUS_PATH:-/run/openvpn/server.status}"
STATUS_PUSH_INTERVAL="${STATUS_PUSH_INTERVAL:-5}" # seconds
PUSH_MGMT="${PUSH_MGMT:-0}"                       # legacy mgmt pusher (off)
PUSH_INTERVAL="${PUSH_INTERVAL:-5}"
PUSH_HEARTBEAT="${PUSH_HEARTBEAT:-60}"

### ===== VPN defaults =====
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
VPN_PORT="${VPN_PORT:-1194}"
VPN_PROTO="${VPN_PROTO:-udp}"            # udp|tcp   (udp strongly recommended)
DNS1="${DNS1:-1.1.1.1}"
DNS2="${DNS2:-8.8.8.8}"
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 18)}"
WG_PORT="${WG_PORT:-51820}"

### ===== Logging =====
LOG_FILE="/root/vpn-deploy.log"
mkdir -p "$(dirname "$LOG_FILE")"
exec > >(tee -i "$LOG_FILE"); exec 2>&1
echo -e "\n================= START $(date -Is) ================="

### ===== Helpers =====
json_escape(){ sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
json_kv(){ printf '"%s":"%s"' "$1" "$(printf '%s' "$2" | json_escape)"; }
curl_json(){ # curl_json <METHOD> <URL> <JSON_STRING>
  local m="$1" u="$2" d="$3"
  curl --retry 3 --retry-delay 2 -fsS -X "$m" \
       -H "Authorization: Bearer $PANEL_TOKEN" \
       -H "Content-Type: application/json" \
       -d "$d" "$u"
}
panel(){    # panel <METHOD> <PATH> [--data json | --file path]
  local method="$1"; shift
  local path="$1"; shift
  local url="${PANEL_URL%/}${path}"
  [[ "$PANEL_CALLBACKS" = "1" ]] || { [[ "${1-}" == "--file" ]] || true; return 0; }
  if [[ "${1-}" == "--file" ]]; then
    curl --retry 3 --retry-delay 2 -fsS -X "$method" \
      -H "Authorization: Bearer $PANEL_TOKEN" -F "file=@$2" "$url"; return
  fi
  local data="{}"; [[ "${1-}" == "--data" ]] && data="$2"
  curl_json "$method" "$url" "$data"
}
announce(){ panel POST "/api/servers/$SERVER_ID/deploy/events" \
  --data "{ $(json_kv status "$1"), $(json_kv message "$2") }" || true; }
logchunk(){ panel POST "/api/servers/$SERVER_ID/deploy/logs" \
  --data "{ $(json_kv line "$1") }" >/dev/null || true; }
fail(){ announce failed "$1"; exit 1; }
trap 'rc=$?; [[ $rc -eq 0 ]] || announce "failed" "Deployment failed (exit=$rc)"; exit $rc' ERR
announce running "Starting deployment"

### ===== System checks =====
[[ $EUID -eq 0 ]] || fail "Run as root"
[[ -c /dev/net/tun ]] || fail "TUN missing"
command -v apt-get >/dev/null || fail "Debian/Ubuntu apt required"

### ===== apt with retries =====
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
apt_try(){ local tries=3 i=1; until "$@"; do (( i>=tries )) && return 1; sleep $((i*2)); ((i++)); done; }

### ===== Pre-clean =====
logchunk "Pre-clean"
killall openvpn 2>/dev/null || true
rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat; dpkg --configure -a || true

### ===== Packages =====
logchunk "Install packages"
apt_try apt-get update -y
apt_try apt-get install -y \
  openvpn easy-rsa wireguard iproute2 iptables-persistent nftables curl \
  ca-certificates python3 jq netcat-openbsd htop

### ===== Network facts =====
DEF_IFACE="$(ip -4 route show default | awk '/default/ {print $5; exit}')" ; DEF_IFACE="${DEF_IFACE:-eth0}"

### ===== Enable forwarding =====
logchunk "Enable IP forwarding"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-aio-vpn.conf
sysctl --system >/dev/null

### ===== Optional: NIC offload tame (safe no-op if ethtool missing) =====
if command -v ethtool >/dev/null 2>&1; then
  ethtool -K "$DEF_IFACE" tx on rx on sg on 2>/dev/null || true
fi

### ===== OpenVPN PKI (idempotent) =====
logchunk "OpenVPN PKI"
install -d -m 0755 /etc/openvpn/easy-rsa
cp -a /usr/share/easy-rsa/* /etc/openvpn/easy-rsa 2>/dev/null || true
cd /etc/openvpn/easy-rsa
export EASYRSA_BATCH=1
if [[ ! -d pki ]]; then
  ./easyrsa init-pki
  ./easyrsa build-ca nopass
  ./easyrsa gen-req server nopass
  ./easyrsa sign-req server server
  ./easyrsa gen-dh
  openvpn --genkey --secret /etc/openvpn/ta.key
  install -m 0644 pki/ca.crt            /etc/openvpn/ca.crt
  install -m 0644 pki/issued/server.crt /etc/openvpn/server.crt
  install -m 0600 pki/private/server.key /etc/openvpn/server.key
  install -m 0644 pki/dh.pem            /etc/openvpn/dh.pem
fi

### ===== Auth store =====
logchunk "Auth file"
install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
else
  umask 077; printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
fi
rm -f /tmp/panel-auth

### ===== Password checker (via-file) =====
cat >/etc/openvpn/auth/checkpsw.sh <<'SH'
#!/bin/sh
set -eu
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
TS="$(date '+%F %T')"
CRED_FILE="$1"
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

### ===== server.conf (tuned) =====
logchunk "Write server.conf (UDP + GCM + buffers + mssfix)"
cat >/etc/openvpn/server.conf <<CONF
port $VPN_PORT
proto $VPN_PROTO
dev tun

ca ca.crt
cert server.crt
key server.key
dh dh.pem
tls-auth ta.key 0

# Modern + fast
data-ciphers AES-128-GCM:AES-256-GCM
data-ciphers-fallback AES-128-GCM
auth SHA256
# no compression
;compress
;push "compress lz4-v2"

topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist ipp.txt
keepalive 10 120
persist-key
persist-tun

# Status + mgmt
status $STATUS_PATH 10
status-version 3
verb 3
management $MGMT_HOST $MGMT_PORT
script-security 3

# Username/password only
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file

# Push routes + DNS
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS $DNS1"
push "dhcp-option DNS $DNS2"

# Throughput helpers (OS autotune socket buffers)
sndbuf 0
rcvbuf 0
push "sndbuf 0"
push "rcvbuf 0"

# Avoid TCP stalls on funky carrier paths
mssfix 1450

# Mobile cleanup
explicit-exit-notify 3
CONF

### ===== NAT + firewall =====
logchunk "NAT + firewall rules"
# Masquerade
iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || \
iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE
# Open ports
iptables -C INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT 2>/dev/null || \
iptables -A INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT
iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || \
iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT
# TCP MSS clamp (iptables)
iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || \
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu
iptables-save >/etc/iptables/rules.v4 || true

# TCP MSS clamp (nftables variant) — add if nft is active
if systemctl is-active --quiet nftables 2>/dev/null; then
  nft list table inet filter >/dev/null 2>&1 || nft add table inet filter
  nft list chain inet filter forward >/dev/null 2>&1 || nft add chain inet filter forward { type filter hook forward priority 0 \; }
  nft list chain inet filter mssfix >/dev/null 2>&1 || nft add chain inet filter mssfix
  nft list ruleset | grep -q 'TCPMSS' || nft add rule inet filter forward tcp flags syn tcp option maxseg set clamp-to-pmtu
  nft -s list ruleset >/dev/null 2>&1 || true
fi

### ===== WireGuard (optional skeleton; service up) =====
logchunk "WireGuard base"
install -d -m 0700 /etc/wireguard
if [[ ! -f /etc/wireguard/server_private_key ]]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
fi
PRIV="$(cat /etc/wireguard/server_private_key)"
cat >/etc/wireguard/wg0.conf <<WG
[Interface]
PrivateKey = $PRIV
Address = 10.66.66.1/24
ListenPort = $WG_PORT
SaveConfig = true
WG
systemctl enable --now wg-quick@wg0

### ===== OpenVPN service =====
logchunk "Start OpenVPN"
systemctl enable openvpn@server
systemctl restart openvpn@server
systemctl is-active --quiet openvpn@server || fail "OpenVPN failed to start"

### ===== Status v3 push agent =====
logchunk "Install status push agent"
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
        rx=int(col('Bytes Received','0') or 0); tx=int(col('Bytes Sent','0') or 0)
        ts=col('Connected Since (time_t)','') or ''
        cid=col('Client ID','') or None
        clients[cn]={"username":cn,"client_ip":real_ip,"virtual_ip":None,
                     "bytes_received":rx,"bytes_sent":tx,
                     "connected_at": int(ts) if ts.isdigit() else None,
                     "client_id": int(cid) if (cid and cid.isdigit()) else None}
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

# Apply interval
sed -i "s/OnUnitActiveSec=.*/OnUnitActiveSec=${STATUS_PUSH_INTERVAL}s/" /etc/systemd/system/ovpn-status-push.timer || true
systemctl daemon-reload
systemctl enable --now ovpn-status-push.timer
systemctl status --no-pager ovpn-status-push.timer >/dev/null 2>&1 || true

### ===== Auth sync (pull from panel every minute) =====
logchunk "Install auth sync"
install -d -m 0755 /usr/local/lib/aiovpn
cat >/usr/local/lib/aiovpn/pull-auth.sh <<SYNC
#!/usr/bin/env bash
set -euo pipefail
[[ "\${PANEL_CALLBACKS:-1}" = "1" ]] || exit 0
TMP=\$(mktemp)
curl --retry 3 --retry-delay 2 -fsS \
  -H "Authorization: Bearer $PANEL_TOKEN" \
  "${PANEL_URL%/}/api/servers/$SERVER_ID/authfile" -o "\$TMP" || exit 0
[ -s "\$TMP" ] || exit 0
install -m 0600 "\$TMP" /etc/openvpn/auth/psw-file
rm -f "\$TMP"
/bin/systemctl reload openvpn@server || true
SYNC
chmod 0750 /usr/local/lib/aiovpn/pull-auth.sh

cat >/etc/systemd/system/aiovpn-auth-sync.service <<'SVC'
[Unit]
Description=AIOVPN pull authfile from panel
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/lib/aiovpn/pull-auth.sh
User=root
Group=root
ProtectSystem=full
ProtectHome=true
NoNewPrivileges=true
SVC

cat >/etc/systemd/system/aiovpn-auth-sync.timer <<'TIM'
[Unit]
Description=Run auth sync every minute
[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
Unit=aiovpn-auth-sync.service
[Install]
WantedBy=timers.target
TIM

systemctl daemon-reload
systemctl enable --now aiovpn-auth-sync.timer

### ===== (Optional) Legacy mgmt pusher =====
if [[ "${PUSH_MGMT:-0}" = "1" ]]; then
  logchunk "Enable legacy mgmt pusher"
  cat >/usr/local/lib/aiovpn/mgmt-pusher.sh <<'PUSH'
#!/usr/bin/env bash
set -euo pipefail
PANEL_URL="${PANEL_URL:?}"; PANEL_TOKEN="${PANEL_TOKEN:?}"; SERVER_ID="${SERVER_ID:?}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"; MGMT_PORT="${MGMT_PORT:-7505}"
INTERVAL="${PUSH_INTERVAL:-5}"; HEARTBEAT="${PUSH_HEARTBEAT:-60}"
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"
STATUS_FILE="/run/openvpn/server.status"
JSON_ESC(){ sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
post(){ [[ "$PANEL_CALLBACKS" = "1" ]] || return 0
  local m; m="$(printf '%s' "$1" | JSON_ESC)"
  curl -fsS -X POST -H "Authorization: Bearer $PANEL_TOKEN" -H "Content-Type: application/json" \
    -d "{\"status\":\"mgmt\",\"message\":\"$m\"}" "${PANEL_URL%/}/api/servers/$SERVER_ID/deploy/events" >/dev/null || true; }
from_file(){ [[ -r "$STATUS_FILE" ]] || return 1; awk -F'\t' '$1=="CLIENT_LIST"{print $2}' "$STATUS_FILE" | paste -sd, -; }
LAST=""; TS=0
while true; do
  CN="$(from_file || true)"; COUNT=0; [ -n "$CN" ] && COUNT="$(tr -cd , <<<"$CN" | wc -c)"; COUNT=$((COUNT + (CN!="")))
  NOW=$(date +%s); PAYLOAD="clients=${COUNT} [${CN}]"
  if [[ "$PAYLOAD" != "$LAST" ]] || (( NOW-TS >= HEARTBEAT )); then post "$PAYLOAD"; LAST="$PAYLOAD"; TS=$NOW; fi
  sleep "$INTERVAL"
done
PUSH
  chmod 0755 /usr/local/lib/aiovpn/mgmt-pusher.sh
  cat >/etc/systemd/system/aiovpn-mgmt-pusher.service <<SVC2
[Unit]
Description=AIOVPN OpenVPN management pusher (legacy)
After=openvpn@server.service network-online.target
Wants=network-online.target
[Service]
Type=simple
Environment=PANEL_URL=$PANEL_URL
Environment=PANEL_TOKEN=$PANEL_TOKEN
Environment=SERVER_ID=$SERVER_ID
Environment=MGMT_HOST=$MGMT_HOST
Environment=MGMT_PORT=$MGMT_PORT
Environment=PUSH_INTERVAL=$PUSH_INTERVAL
Environment=PUSH_HEARTBEAT=$PUSH_HEARTBEAT
Environment=PANEL_CALLBACKS=$PANEL_CALLBACKS
ExecStart=/usr/local/lib/aiovpn/mgmt-pusher.sh
Restart=always
RestartSec=2
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
[Install]
WantedBy=multi-user.target
SVC2
  systemctl daemon-reload
  systemctl enable --now aiovpn-mgmt-pusher.service
fi

### ===== Panel facts + auth mirror =====
panel POST "/api/servers/$SERVER_ID/deploy/facts" \
  --data "{ $(json_kv iface "$DEF_IFACE"), $(json_kv proto "$VPN_PROTO"), \"mgmt_port\": $MGMT_PORT, \"vpn_port\": $VPN_PORT, \"ip_forward\": 1 }" >/dev/null || true
panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true

announce succeeded "Deployment complete"
echo "✅ Done $(date -Is)"