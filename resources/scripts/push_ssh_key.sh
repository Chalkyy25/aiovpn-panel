#!/bin/bash

# Run Laravel Artisan to get server IPs as JSON
VPN_SERVERS=$(php artisan tinker --execute="echo json_encode(App\Models\VpnServer::pluck('ip_address')->toArray());")

# Remove brackets and quotes
VPN_SERVERS=$(echo "$VPN_SERVERS" | sed 's/[][]//g' | tr -d '"')

SSH_USER="root"
PUBKEY=$(cat /root/.ssh/id_rsa.pub)

echo -e "\n🚀 Starting key push to servers from Laravel...\n"

# Loop through IPs
for SERVER in $VPN_SERVERS; do
  echo "🔐 Pushing key to $SERVER..."

  ssh -o StrictHostKeyChecking=no $SSH_USER@"$SERVER" "
    mkdir -p ~/.ssh &&
    echo \"$PUBKEY\" >> ~/.ssh/authorized_keys &&
    chmod 600 ~/.ssh/authorized_keys &&
    chmod 700 ~/.ssh
  "

  # shellcheck disable=SC2181
  if [[ $? -eq 0 ]]; then
    echo "✅ Key added to $SERVER"
  else
    echo "❌ Failed to add key to $SERVER"
  fi

  echo ""
done

echo "🎉 Done."
