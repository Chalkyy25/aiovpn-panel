#!/bin/bash

echo "🔑 Getting TLS-Crypt Key from Germany Server"
echo "=============================================="

# Check if server has OpenVPN running
if ! pgrep -f "openvpn.*server" > /dev/null; then
    echo "❌ OpenVPN server is not running!"
    echo "💡 Start OpenVPN server first"
    exit 1
fi

# Find the tls-crypt key file
echo "🔍 Looking for tls-crypt key..."

# Common locations for tls-crypt key
TLS_KEY_LOCATIONS=(
    "/etc/openvpn/server/ta.key"
    "/etc/openvpn/ta.key" 
    "/etc/openvpn/tls-crypt.key"
    "/etc/openvpn/server/tls-crypt.key"
    "/etc/openvpn/server/tc.key"
)

for location in "${TLS_KEY_LOCATIONS[@]}"; do
    if [[ -f "$location" ]]; then
        echo "✅ Found tls-crypt key at: $location"
        echo ""
        echo "📋 TLS-Crypt Key Content:"
        echo "========================="
        cat "$location"
        echo ""
        echo "🎯 Copy this key and replace the <tls-crypt> section in your config"
        exit 0
    fi
done

# If not found, check server config for tls-crypt directive
echo "🔍 Checking OpenVPN server config for tls-crypt directive..."

CONFIG_LOCATIONS=(
    "/etc/openvpn/server.conf"
    "/etc/openvpn/server/server.conf"
    "/etc/openvpn/openvpn-server.conf"
)

for config in "${CONFIG_LOCATIONS[@]}"; do
    if [[ -f "$config" ]]; then
        echo "📄 Checking config: $config"
        
        # Look for tls-crypt directive
        tls_crypt_line=$(grep "^tls-crypt" "$config" 2>/dev/null)
        if [[ -n "$tls_crypt_line" ]]; then
            key_file=$(echo "$tls_crypt_line" | awk '{print $2}')
            echo "✅ Found tls-crypt directive: $tls_crypt_line"
            
            if [[ -f "$key_file" ]]; then
                echo "✅ Key file exists: $key_file"
                echo ""
                echo "📋 TLS-Crypt Key Content:"
                echo "========================="
                cat "$key_file"
                echo ""
                echo "🎯 Copy this key and replace the <tls-crypt> section in your config"
                exit 0
            else
                echo "❌ Key file not found: $key_file"
            fi
        fi
    fi
done

echo "❌ Could not find tls-crypt key!"
echo ""
echo "💡 Manual steps:"
echo "1. Find your OpenVPN server config:"
echo "   find /etc/openvpn -name '*.conf' -type f"
echo ""
echo "2. Look for tls-crypt directive:"
echo "   grep -r 'tls-crypt' /etc/openvpn/"
echo ""
echo "3. Copy the key file content and send it to me"