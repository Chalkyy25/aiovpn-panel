## Device Limit Auto-Kill - Quick Reference

### ✅ What It Does
When a user connects a **new device** and exceeds their `max_connections` limit:
- **Oldest device is KILLED** on the VPN server (not just DB update)
- **New device connects** successfully
- **Happens automatically** every time management data arrives

---

### 🔍 How to Check If It's Working

**Watch logs in real-time:**
```bash
tail -f storage/logs/vpn.log | grep DEVICE_LIMIT
```

**Look for these log entries:**
```
✂️ Killed WIREGUARD session ...  # Session was actually killed
✂️ Killed OPENVPN session ...    # Session was actually killed
```

---

### ⚙️ Set User Device Limits

**Via Database:**
```sql
UPDATE vpn_users SET max_connections = 1 WHERE username = 'john';  -- Single device
UPDATE vpn_users SET max_connections = 3 WHERE username = 'jane';  -- 3 devices
UPDATE vpn_users SET max_connections = 0 WHERE username = 'admin'; -- Unlimited
```

**Via Code:**
```php
$user->update(['max_connections' => 1]); // Single device only
```

---

### 🧪 Quick Test

1. Create user with `max_connections = 1`
2. Connect phone → should work ✅
3. Connect laptop → phone gets kicked ✂️
4. Check logs → should see "Killed" message

---

### 🎯 Files Modified

- `app/Http/Controllers/Api/DeployEventController.php` - Added killSession()
- `app/Models/VpnUser.php` - Added killVpnSession()
- `WIREGUARD_DEVICE_LIMIT.md` - Full documentation

---

### 🆘 Troubleshooting

**Not killing sessions?**
- Check SSH connectivity: `ssh root@vpn-server "wg show"`
- Verify logs: `grep "Failed to kill" storage/logs/vpn.log`
- Check `max_connections` is set: `SELECT username, max_connections FROM vpn_users;`

**Sessions still active after "kill"?**
- WireGuard: Verify interface name (default: `wg0`)
- OpenVPN: Check management port is 7505
- Check server logs on VPN server itself

---

### 📊 Database Check

```sql
-- See who's connected and if they're over limit
SELECT 
    u.username,
    u.max_connections as 'limit',
    COUNT(*) as active,
    CASE 
        WHEN u.max_connections = 0 THEN '✅ Unlimited'
        WHEN COUNT(*) <= u.max_connections THEN '✅ OK'
        ELSE '❌ OVER LIMIT'
    END as status
FROM vpn_user_connections c
JOIN vpn_users u ON u.id = c.vpn_user_id
WHERE c.is_connected = true
GROUP BY u.id;
```

---

**Last Updated:** Dec 22, 2024
