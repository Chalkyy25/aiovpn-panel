#!/usr/bin/env bash
# Fix VPN Dashboard Monitoring - Merge UDP + TCP status pushes

# Create merged monitoring script
cat > /usr/local/bin/ovpn-mgmt-push.sh <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
. /etc/default/ovpn-status-push 2>/dev/null || true
: "${PANEL_URL:?}"; : "${PANEL_TOKEN:?}"; : "${SERVER_ID:?}"

parse() { python3 - "$@" <<'PY'
import sys,csv,json,datetime
def parse_status(txt):
    clients,virt,hCL,hRT={}, {}, {}, {}
    for row in csv.reader(txt.splitlines()):
        if not row: continue
        tag=row[0]
        if tag=='HEADER' and len(row)>2:
            if row[1]=='CLIENT_LIST': hCL={n:i for i,n in enumerate(row)}
            elif row[1]=='ROUTING_TABLE': hRT={n:i for i,n in enumerate(row)}
            continue
        if tag=='CLIENT_LIST':
            def col(h,d=''): i=hCL.get(h); return row[i] if i is not None and i<len(row) else d
            cn=col('Common Name') or col('Username')
            if not cn: continue
            real=col('Real Address') or ''
            real_ip=real.split(':')[0] if real else None
            def toint(x):
                try: return int(x)
                except: return 0
            clients[cn]={"username":cn,"client_ip":real_ip,"virtual_ip":None,
                         "bytes_received":toint(col('Bytes Received','0')),
                         "bytes_sent":toint(col('Bytes Sent','0')),
                         "connected_at":toint(col('Connected Since (time_t)','0'))}
        elif tag=='ROUTING_TABLE':
            def col(h,d=''): i=hRT.get(h); return row[i] if i is not None and i<len(row) else d
            virt[col('Common Name','')]=col('Virtual Address') or None
    for cn,ip in virt.items():
        if cn in clients and ip: clients[cn]["virtual_ip"]=ip
    return list(clients.values())

merged=[]
for txt in sys.stdin.read().split("\n===SPLIT===\n"):
    if txt.strip():
        merged.extend(parse_status(txt))
print(json.dumps({"status":"mgmt","ts":datetime.datetime.utcnow().isoformat()+"Z",
                  "clients":len(merged),"users":merged}, separators=(',',':')))
PY
}
post(){ curl -fsS -X POST -H "Authorization: Bearer ${PANEL_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-raw "$(cat)" "${PANEL_URL%/}/api/servers/${SERVER_ID}/events" >/dev/null || true; }

collect() {
  local out=""
  for p in 7505 7506; do
    if nc -z 127.0.0.1 "$p" 2>/dev/null; then
      out+=$( ( printf "status 3\nquit\n" | nc -w 2 127.0.0.1 "$p" ) || true )
      out+="\n===SPLIT===\n"
    fi
  done
  printf "%b" "$out"
}
collect | parse | post
SCRIPT

chmod +x /usr/local/bin/ovpn-mgmt-push.sh

# Add hooks to OpenVPN configs
sed -i '/^client-connect\|^client-disconnect/d' /etc/openvpn/server/server.conf
echo 'client-connect "/usr/local/bin/ovpn-mgmt-push.sh"' >> /etc/openvpn/server/server.conf
echo 'client-disconnect "/usr/local/bin/ovpn-mgmt-push.sh"' >> /etc/openvpn/server/server.conf

sed -i '/^client-connect\|^client-disconnect/d' /etc/openvpn/server/server-tcp.conf
echo 'client-connect "/usr/local/bin/ovpn-mgmt-push.sh"' >> /etc/openvpn/server/server-tcp.conf
echo 'client-disconnect "/usr/local/bin/ovpn-mgmt-push.sh"' >> /etc/openvpn/server/server-tcp.conf

# Create systemd service & timer
cat > /etc/systemd/system/ovpn-mgmt-push.service <<'SVC'
[Unit]
Description=Push merged OpenVPN mgmt status to panel
[Service]
Type=oneshot
EnvironmentFile=-/etc/default/ovpn-status-push
ExecStart=/usr/local/bin/ovpn-mgmt-push.sh
SVC

cat > /etc/systemd/system/ovpn-mgmt-push.timer <<'TIM'
[Unit]
Description=Push OpenVPN mgmt status every 2s (fallback)
[Timer]
OnBootSec=3s
OnUnitActiveSec=2s
AccuracySec=1s
Unit=ovpn-mgmt-push.service
[Install]
WantedBy=timers.target
TIM

# Apply changes
systemctl daemon-reload
systemctl disable --now ovpn-status-push.timer ovpn-status-push-tcp.timer 2>/dev/null || true
systemctl enable --now ovpn-mgmt-push.timer
systemctl restart openvpn-server@server openvpn-server@server-tcp

echo "✅ Dashboard monitoring fixed!"
echo "   - Merged UDP + TCP status pushes"
echo "   - Real-time updates via hooks"
echo "   - 2-second fallback timer"
systemctl status ovpn-mgmt-push.timer --no-pager
