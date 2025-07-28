#!/bin/bash

# Use dynamically detected PHP binary
PHP_BIN=$(command -v php || echo "/usr/bin/php")

# Fetch VPN servers (newline-separated for easier parsing)
VPN_SERVERS=$($PHP_BIN artisan tinker --execute="echo implode(' ', App\Models\VpnServer::pluck('ip_address')->toArray());")

# Check if any servers were returned
if [[ -z "$VPN_SERVERS" ]]; then
    echo "❌ Error: No VPN servers found!"
    exit 1
fi

# SSH configuration
SSH_USER="${SSH_USER:-root}"
PUBKEY=$(cat "${PUBKEY_PATH:-/root/.ssh/id_rsa.pub}")
MAX_CONNECTIONS=${MAX_CONNECTIONS:-10}
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-10}
LOGFILE="/var/log/vpn_key_push.log"

# Set up logging
exec > >(tee -a "$LOGFILE") 2>&1

echo -e "\n🚀 Starting key push to servers from Laravel...\n"

# Process each server
while read -r SERVER; do
    [[ -z "$SERVER" ]] && continue

    # Debug: Trim whitespace/comma issues
    SERVER=$(echo "$SERVER" | tr -d '[:space:],')

    # Validate IP address format
    if ! [[ $SERVER =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "⚠️  Skipping invalid IP: $SERVER"
        continue
    fi

    echo "🔐 Pushing key to $SERVER..."

    # Add fingerprint to known_hosts if missing
    grep -q "$SERVER" ~/.ssh/known_hosts || ssh-keyscan -H "$SERVER" >> ~/.ssh/known_hosts 2>/dev/null

    # Secure the known_hosts file
    chmod 644 ~/.ssh/known_hosts

    # Push key via SSH
    ssh -n \
        -o StrictHostKeyChecking=no \
        -o ConnectTimeout="$CONNECT_TIMEOUT" \
        -o MaxSessions="$MAX_CONNECTIONS" \
        "$SSH_USER"@"$SERVER" bash -s <<EOF
mkdir -p ~/.ssh && chmod 700 ~/.ssh
touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
grep -qxF "$PUBKEY" ~/.ssh/authorized_keys || echo "$PUBKEY" >> ~/.ssh/authorized_keys
EOF

    # Check result
    # shellcheck disable=SC2181
    if [[ $? -eq 0 ]]; then
        echo "✅ Key added/verified on $SERVER"
    else
        echo "❌ Failed to access $SERVER"
    fi
    echo ""
done <<< "$(echo "$VPN_SERVERS" | awk NF)"

echo "🎉 Done."
