#!/usr/bin/env bash
# Fix OpenVPN config paths and stop legacy services

echo "🔧 Fixing OpenVPN configuration paths..."

# Stop legacy services to free up ports
systemctl stop openvpn@server 2>/dev/null || true
systemctl disable openvpn@server 2>/dev/null || true
systemctl stop openvpn@server-tcp 2>/dev/null || true
systemctl disable openvpn@server-tcp 2>/dev/null || true

# Fix UDP config paths
sed -i 's|^ca ca.crt|ca /etc/openvpn/ca.crt|' /etc/openvpn/server/server.conf
sed -i 's|^cert server.crt|cert /etc/openvpn/server.crt|' /etc/openvpn/server/server.conf
sed -i 's|^key server.key|key /etc/openvpn/server.key|' /etc/openvpn/server/server.conf
sed -i 's|^dh dh.pem|dh /etc/openvpn/dh.pem|' /etc/openvpn/server/server.conf
sed -i 's|^tls-crypt ta.key|tls-crypt /etc/openvpn/ta.key|' /etc/openvpn/server/server.conf

# Restart modern services
systemctl daemon-reload
systemctl restart openvpn-server@server
systemctl restart openvpn-server@server-tcp

# Check status
echo ""
echo "✅ Status check:"
systemctl is-active openvpn-server@server && echo "  UDP service: running" || echo "  UDP service: FAILED"
systemctl is-active openvpn-server@server-tcp && echo "  TCP service: running" || echo "  TCP service: FAILED"

echo ""
echo "📊 Management ports:"
netstat -tlnp | grep -E ':(7505|7506)' || echo "  No listeners on 7505/7506"
