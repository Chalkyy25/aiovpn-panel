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

# Set default values for script variables
SSH_USER="${SSH_USER:-root}"
PUBKEY=$(cat "${PUBKEY_PATH:-/root/.ssh/id_rsa.pub}")
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-10}

# Debug: Log variable values
echo "Debug: SSH_USER='$SSH_USER', CONNECT_TIMEOUT='$CONNECT_TIMEOUT', PUBKEY_PATH='$PUBKEY_PATH'"

# Process each server line-by-line
while read -r SERVER; do
    echo "Debug: Processing SERVER='$SERVER'"

    [[ -z "$SERVER" ]] && { echo "❌ Skipping empty server."; continue; }

    # Trim whitespace and validate IP
    SERVER=$(echo "$SERVER" | tr -d '[:space:],')
    if ! [[ $SERVER =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "⚠️  Skipping invalid IP: $SERVER"
        continue
    fi

    echo "🔐 Pushing key to $SERVER..."

    # Add fingerprint to known_hosts if missing
    if ! grep -q "$SERVER" ~/.ssh/known_hosts; then
        ssh-keyscan -H "$SERVER" >> ~/.ssh/known_hosts 2>/dev/null
        echo "🔑 Added $SERVER to known_hosts"
    fi

    # Ensure proper permissions for known_hosts
    chmod 644 ~/.ssh/known_hosts

    # Push the key
    ssh -n \
        -o StrictHostKeyChecking=no \
        -o ConnectTimeout="$CONNECT_TIMEOUT" \
        -o ServerAliveInterval=60 \
        -o ServerAliveCountMax=3 \
        "$SSH_USER"@"$SERVER" bash -s <<EOF
mkdir -p ~/.ssh && chmod 700 ~/.ssh
touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
grep -qxF "$PUBKEY" ~/.ssh/authorized_keys || echo "$PUBKEY" >> ~/.ssh/authorized_keys
EOF

    # Check the result
    STATUS=$?
    if [[ $STATUS -eq 0 ]]; then
        echo "✅ Key added/verified on $SERVER"
    else
        echo "❌ Failed to access $SERVER with status $STATUS"
    fi

    echo "" # Blank line for spacing
done <<< "$VPN_SERVERS"

# Set default values for SSH options if not already set
SSH_USER="${SSH_USER:-root}"              # Default to 'root' user
PUBKEY=$(cat "${PUBKEY_PATH:-/root/.ssh/id_rsa.pub}")  # Default public key
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-10}    # Default timeout to 10 seconds

# Debug: Log variable values for tracing
echo "Debug: SSH_USER='$SSH_USER', CONNECT_TIMEOUT='$CONNECT_TIMEOUT', PUBKEY_PATH='$PUBKEY_PATH'"

# SSH configuration
SSH_USER="${SSH_USER:-root}"
PUBKEY=$(cat "${PUBKEY_PATH:-/root/.ssh/id_rsa.pub}")
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-10}
LOGFILE="/var/log/vpn_key_push.log"

# Set up logging
exec > >(tee -a "$LOGFILE") 2>&1
