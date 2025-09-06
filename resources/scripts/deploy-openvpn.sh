#!/bin/bash
set -euo pipefail

# ===== Required env =====
: "${PANEL_URL:?set PANEL_URL, e.g. https://aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

# ===== Optional toggles =====
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"       # 1=POST back to panel, 0=quiet
PUSH_MGMT="${PUSH_MGMT:-0}"                   # 0=disable legacy mgmt pusher (avoid duplicates)
PUSH_INTERVAL="${PUSH_INTERVAL:-5}"           # (legacy pusher) seconds between polls
PUSH_HEARTBEAT="${PUSH_HEARTBEAT:-60}"        # (legacy pusher) heartbeat seconds

# ===== Status v3 agent options =====
STATUS_PATH="${STATUS_PATH:-/run/openvpn/server.status}"
STATUS_PUSH_INTERVAL="${STATUS_PUSH_INTERVAL:-5}"   # seconds between pushes

# ===== VPN defaults =====
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
VPN_PORT="${VPN_PORT:-1194}"
VPN_PROTO="${VPN_PROTO:-udp}"               # udp|tcp
DNS1="${DNS1:-1.1.1.1}"
DNS2="${DNS2:-8.8.8.8}"
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 18)}"
WG_PORT="${WG_PORT:-51820}"

# ===== Logging =====
LOG_FILE="/root/vpn-deploy.log"
exec > >(tee -i "$LOG_FILE")
exec 2>&1
echo -e "\n=======================================\nSTART $(date)\n======================================="

# ===== Helpers =====
json_escape() { sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
json_kv() { printf '"%s":"%s"' "$1" "$(printf '%s' "$2" | json_escape)"; }

curl_json() {
  # curl_json <METHOD> <URL> <JSON_STRING>
  local m="$1" u="$2" d="$3"
  curl --retry 3 --retry-delay 2 -fsS -X "$m" \
    -H "Authorization: Bearer $PANEL_TOKEN" \
    -H "Content-Type: application/json" \
    -d "$d" "$u"
}

panel() {
  # panel <METHOD> <PATH> [--data json | --file path]
  local method="$1"; shift
  local path="$1"; shift
  local url="${PANEL_URL%/}${path}"

  if [[ "${1-}" == "--file" ]]; then
    [[ "$PANEL_CALLBACKS" = "1" ]] || return 0
    curl --retry 3 --retry-delay 2 -fsS -X "$method" \
      -H "Authorization: Bearer $PANEL_TOKEN" \
      -F "file=@$2" "$url"
    return
  fi

  local data=""
  if [[ "${1-}" == "--data" ]]; then data="$2"; fi
  if [[ "$PANEL_CALLBACKS" = "1" ]]; then
    curl_json "$method" "$url" "$data"
  fi
}

announce() { panel POST "/api/servers/$SERVER_ID/deploy/events" \
  --data "{ $(json_kv status "$1"), $(json_kv message "$2") }" || true; }
logchunk() { panel POST "/api/servers/$SERVER_ID/deploy/logs" \
  --data "{ $(json_kv line "$1") }" >/dev/null || true; }

fail() { announce failed "$1"; exit 1; }
trap 'rc=$?; announce "failed" "Deployment failed (exit=$rc)"; exit $rc' ERR
announce running "Starting deployment"

# ===== System checks =====
[[ $EUID -eq 0 ]] || fail "Run as root"
[[ -c /dev/net/tun ]] || fail "TUN missing"
for c in apt-get systemctl iptables ip curl; do
  command -v "$c" >/dev/null || fail "Missing command: $c"
done

# ===== apt with retries =====
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
apt_try() {
  local tries=3 i=1
  until "$@"; do
    (( i >= tries )) && return 1
    sleep $((i*2)); ((i++))
  done
}

# ===== Pre-clean =====
logchunk "Pre-clean"
killall openvpn 2>/dev/null || true
rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
dpkg --configure -a || true

# ===== Install deps =====
logchunk "Installing packages"
apt_try apt-get update -y
apt_try apt-get install -y \
  openvpn easy-rsa wireguard iproute2 iptables-persistent curl \
  ca-certificates python3 jq netcat-openbsd

# ===== OpenVPN PKI/config =====
logchunk "OpenVPN PKI/config"
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
  install -m 0644 pki/ca.crt /etc/openvpn/ca.crt
  install -m 0644 pki/issued/server.crt /etc/openvpn/server.crt
  install -m 0600 pki/private/server.key /etc/openvpn/server.key
  install -m 0644 pki/dh.pem /etc/openvpn/dh.pem
fi

# ===== Auth file =====
install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
else
  umask 077
  printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
  chmod 600 /etc/openvpn/auth/psw-file
fi
rm -f /tmp/panel-auth

# ===== Password checker =====
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
USERNAME="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f1)"
PASSWORD="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f2-)"
[ -n "$USERNAME" ] && [ -n "$PASSWORD" ] || { echo "$TS: Empty user/pass" >>"$LOG_FILE"; exit 1; }
CORRECT_PASSWORD="$(awk -v u="$USERNAME" '$1==u { $1=""; sub(/^[ \t]+/,""); print; exit }' "$PASSFILE")"
if [ -n "$CORRECT_PASSWORD" ] && [ "$PASSWORD" = "$CORRECT_PASSWORD" ]; then
  echo "$TS: OK $USERNAME" >>"$LOG_FILE"; exit 0
fi
echo "$TS: FAIL $USERNAME" >>"$LOG_FILE"; exit 1
SH
chmod 0755 /etc/openvpn/auth/checkpsw.sh

# ===== Server config (management + status v3) =====
cat >/etc/openvpn/server.conf <<CONF
port $VPN_PORT
proto $VPN_PROTO
dev tun
ca ca.crt
cert server.crt
key server.key
dh dh.pem
auth SHA256
tls-auth ta.key 0
topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist ipp.txt
keepalive 10 120
persist-key
persist-tun
status /run/openvpn/server.status 10
status-version 3
verb 3
management $MGMT_HOST $MGMT_PORT
script-security 3
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS $DNS1"
push "dhcp-option DNS $DNS2"
explicit-exit-notify 1
CONF

# ===== Firewall/NAT =====
logchunk "IP forwarding + NAT"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-vpn.conf
sysctl --system >/dev/null
DEF_IFACE="$(ip -4 route show default | awk '/default/ {print $5; exit}')" ; DEF_IFACE="${DEF_IFACE:-eth0}"
iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE
iptables -C INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT
iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT
iptables-save >/etc/iptables/rules.v4 || true
netfilter-persistent save || true

# ===== WireGuard (optional) =====
logchunk "WireGuard setup"
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

# ===== OpenVPN service =====
logchunk "Start OpenVPN"
systemctl enable openvpn@server
systemctl restart openvpn@server
systemctl is-active --quiet openvpn@server || fail "OpenVPN failed to start"

# ===== Status-v3 push agent (rich users[] to /api/servers/{id}/events) =====
logchunk "Install status-v3 push agent"
cat >/usr/local/bin/ovpn-status-push.sh <<'AGENT'
#!/usr/bin/env bash
set -euo pipefail
PANEL_URL="${PANEL_URL:?}"
SERVER_ID="${SERVER_ID:?}"
PANEL_TOKEN="${PANEL_TOKEN:?}"
STATUS_PATH="${STATUS_PATH:-/run/openvpn/server.status}"

JSON_PAYLOAD="$(/usr/bin/env python3 - "$STATUS_PATH" <<'PY'
import sys, csv, json, datetime, io

def sniff_delim(sample: str) -> str:
    # choose delimiter with more occurrences: comma vs tab
    c = sample.count(',')
    t = sample.count('\t')
    return ',' if c >= t else '\t'

def iso_from_epoch(v):
    try:
        v = int(v)
        return datetime.datetime.utcfromtimestamp(v).isoformat() + "Z"
    except:
        return None

path = sys.argv[1]
clients_by_cn = {}
virt_by_cn = {}
hdr_CL = {}
hdr_RT = {}

try:
    with open(path, 'r', newline='') as fh:
        sample = fh.read(4096)
        fh.seek(0)
        delim = sniff_delim(sample)
        reader = csv.reader(fh, delimiter=delim)
        for row in reader:
            if not row:
                continue
            tag = row[0]
            if tag == 'HEADER' and len(row) > 2:
                if row[1] == 'CLIENT_LIST':
                    hdr_CL = {name: idx for idx, name in enumerate(row)}
                elif row[1] == 'ROUTING_TABLE':
                    hdr_RT = {name: idx for idx, name in enumerate(row)}
                continue

            if tag == 'CLIENT_LIST':
                def col(h, default=''):
                    i = hdr_CL.get(h)
                    return row[i] if i is not None and i < len(row) else default
                cn = col('Common Name') or col('Username') or ''
                if not cn:
                    continue
                real = col('Real Address') or None
                real_ip = real.split(':')[0] if real else None
                rx = int(col('Bytes Received','0') or 0)
                tx = int(col('Bytes Sent','0') or 0)
                ts_epoch = col('Connected Since (time_t)','') or ''
                client_id = col('Client ID','') or None

                clients_by_cn[cn] = {
                    "username": cn,
                    "client_ip": real_ip,
                    "virtual_ip": None,  # filled from ROUTING_TABLE
                    "bytes_received": rx,
                    "bytes_sent": tx,
                    "connected_at": int(ts_epoch) if ts_epoch.isdigit() else None,
                    "client_id": int(client_id) if (client_id and client_id.isdigit()) else None
                }

            elif tag == 'ROUTING_TABLE':
                def col(h, default=''):
                    i = hdr_RT.get(h)
                    return row[i] if i is not None and i < len(row) else default
                virt = col('Virtual Address') or None
                cn = col('Common Name') or ''
                if cn and virt:
                    virt_by_cn[cn] = virt
except FileNotFoundError:
    pass

# stitch virtual IPs
for cn, virt in virt_by_cn.items():
    if cn in clients_by_cn and virt:
        clients_by_cn[cn]["virtual_ip"] = virt

# output
uniq = list(clients_by_cn.values())
payload = {
    "status": "mgmt",
    "ts": datetime.datetime.utcnow().isoformat()+"Z",
    "clients": len(uniq),
    "cn_list": ",".join([c["username"] for c in uniq]),
    "users": uniq
}
print(json.dumps(payload, separators=(",",":")))
PY
)"
curl -sS -X POST \
  -H "Authorization: Bearer ${PANEL_TOKEN}" \
  -H "Content-Type: application/json" \
  --data-raw "${JSON_PAYLOAD}" \
  "${PANEL_URL%/}/api/servers/${SERVER_ID}/events" \
  >/dev/null 2>&1 || true
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
Description=Post OpenVPN status (v3) to panel on interval
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

# apply interval
sed -i "s/OnUnitActiveSec=.*/OnUnitActiveSec=${STATUS_PUSH_INTERVAL}s/" /etc/systemd/system/ovpn-status-push.timer || true

# disable any old mgmt pusher to prevent duplicates
systemctl disable --now aiovpn-mgmt-pusher.service 2>/dev/null || true
rm -f /usr/local/lib/aiovpn/mgmt-pusher.sh /etc/systemd/system/aiovpn-mgmt-pusher.service 2>/dev/null || true

systemctl daemon-reload
systemctl enable --now ovpn-status-push.timer
systemctl status --no-pager ovpn-status-push.timer >/dev/null 2>&1 || true

# ===== Facts + auth mirror =====
panel POST "/api/servers/$SERVER_ID/deploy/facts" \
  --data "{ $(json_kv iface "$DEF_IFACE"), $(json_kv proto "$VPN_PROTO"), \"mgmt_port\": $MGMT_PORT, \"vpn_port\": $VPN_PORT, \"ip_forward\": 1 }" >/dev/null || true
panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true

# ===== Auth sync timer =====
logchunk "Install auth sync timer"
install -d -m 0755 /usr/local/lib/aiovpn
cat >/usr/local/lib/aiovpn/pull-auth.sh <<SYNC
#!/bin/bash
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

# ===== (LEGACY) Management "pusher" — optional/disabled by default =====
if [[ "${PUSH_MGMT:-0}" = "1" ]]; then
  logchunk "Install legacy management pusher"
  cat >/usr/local/lib/aiovpn/mgmt-pusher.sh <<'PUSH'
#!/bin/bash
set -euo pipefail
PANEL_URL="${PANEL_URL:?}"
PANEL_TOKEN="${PANEL_TOKEN:?}"
SERVER_ID="${SERVER_ID:?}"
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
INTERVAL="${PUSH_INTERVAL:-5}"
HEARTBEAT="${PUSH_HEARTBEAT:-60}"
PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"
STATUS_FILE="/run/openvpn/server.status"
JSON_ESC(){ sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
post_event(){ # $1=message
  [[ "$PANEL_CALLBACKS" = "1" ]] || return 0
  local msg; msg="$(printf '%s' "$1" | JSON_ESC)"
  curl --retry 2 --retry-delay 2 -fsS -X POST \
    -H "Authorization: Bearer $PANEL_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"status\":\"mgmt\",\"message\":\"$msg\"}" \
    "${PANEL_URL%/}/api/servers/$SERVER_ID/deploy/events" >/dev/null || true
}
from_file() {
  [[ -r "$STATUS_FILE" ]] || return 1
  local COUNT CN_LIST
  COUNT="$(awk -F'\t' '$1=="CLIENT_LIST"{c++} END{print c+0}' "$STATUS_FILE")"
  CN_LIST="$(awk -F'\t' '$1=="CLIENT_LIST"{print $2}' "$STATUS_FILE" | paste -sd, -)"
  printf '%s\t%s\n' "$COUNT" "$CN_LIST"
}
from_mgmt() {
  command -v nc >/dev/null || return 1
  local OUT
  OUT="$( { { printf 'status 3\r\n'; printf 'quit\r\n'; } 2>/dev/null | nc -w 3 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null; } || true )"
  [[ -n "$OUT" ]] || return 1
  local COUNT CN_LIST
  COUNT="$(printf '%s\n' "$OUT" | awk -F'\t' '/^CLIENT_LIST/{c++} END{print c+0}')"
  CN_LIST="$(printf '%s\n' "$OUT" | awk -F'\t' '/^CLIENT_LIST/{print $2}' | paste -sd, -)"
  printf '%s\t%s\n' "$COUNT" "$CN_LIST"
}
LAST_PAYLOAD=""; LAST_TS=0
while true; do
  TS="$(date -u +%FT%TZ)"
  PAYLOAD=""
  if DATA="$(from_file)"; then
    COUNT="${DATA%%$'\t'*}"; CN_LIST="${DATA#*$'\t'}"
    PAYLOAD="source=file clients=${COUNT} [${CN_LIST}]"
  elif DATA="$(from_mgmt)"; then
    COUNT="${DATA%%$'\t'*}"; CN_LIST="${DATA#*$'\t'}"
    PAYLOAD="source=mgmt clients=${COUNT} [${CN_LIST}]"
  else
    PAYLOAD="source=none clients=0 []"
  fi
  NOW=$(date +%s)
  if [[ "$PAYLOAD" != "$LAST_PAYLOAD" ]] || (( NOW - LAST_TS >= HEARTBEAT )); then
    post_event "ts=$TS $PAYLOAD"
    LAST_PAYLOAD="$PAYLOAD"
    LAST_TS=$NOW
  fi
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

announce succeeded "Deployment complete"
echo -e "\n✅ Done @ $(date)"