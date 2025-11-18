#!/usr/bin/env bash

BASE="https://panel.aiovpn.co.uk"
TOKEN="162|JyBZJRcLsEUWsZp9l6E8ZK6vkeY400PbSDrVVbUD52f26112"

echo "=== 1) /api/ping ==="
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/ping" | jq .

echo
echo "=== 2) /api/locations ==="
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/locations" | jq .

echo
echo "=== 3) /api/wg/servers ==="
curl -s \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/wg/servers" | jq .

# pick a server id to test configs with
SERVER_ID=116   # Germany
USER_ID=82      # from /api/ping (aiovpn-test)

echo
echo "=== 4) /api/wg/config?server_id=$SERVER_ID (WireGuard config, plain text) ==="
curl -s \
  -H "Accept: text/plain" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/wg/config?server_id=$SERVER_ID"

echo
echo "=== 5) /api/ovpn (OpenVPN config) ==="
echo "--- with explicit user_id + server_id ---"
curl -s \
  -H "Accept: text/plain" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/ovpn?user_id=$USER_ID&server_id=$SERVER_ID" | head -n 40

echo
echo "--- with only server_id (if controller ignores user_id) ---"
curl -s \
  -H "Accept: text/plain" \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/api/ovpn?server_id=$SERVER_ID" | head -n 40
