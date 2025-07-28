#!/bin/bash

# Use dynamically detected PHP binary
PHP_BIN=$(command -v php || echo "/usr/bin/php")

# Fetch VPN servers (newline-separated output)
VPN_SERVERS=$($PHP_BIN artisan tinker --execute="echo implode(PHP_EOL, App\Models\VpnServer::pluck('ip_address')->toArray());" || { echo "Error: Could not fetch VPN servers."; exit 1; })

# Debug: Print raw server output
echo "Debug: Raw VPN_SERVERS='$VPN_SERVERS'"

# Ensure newline-separated format
VPN_SERVERS=$(echo "$VPN_SERVERS" | tr ',' '\n' | awk NF)

# Check if any servers were found
if [[ -z "$VPN_SERVERS" ]]; then
    echo "❌ Error: No VPN servers found!"
    exit 1
fi

# SSH configuration
SSH_USER="${SSH_USER:-root}"
PUBKEY=$(cat "${PUBKEY_PATH:-/root/.ssh/id_rsa.pub}")
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-10}
LOGFILE="/var/log/vpn_key_push.log"

# Set up logging
exec > >(tee -a "$LOGFILE") 2>&1
