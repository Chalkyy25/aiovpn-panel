<?php

namespace App\Models;

use App\Traits\ExecutesRemoteCommands;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VpnServer extends Model
{
    use HasFactory, ExecutesRemoteCommands;

    protected $appends = ['is_online'];
    protected $fillable = [
        'name',
        'ip_address',
        'protocol',
        'ssh_port',
        'ssh_type',
        'ssh_key',
        'ssh_password',
        'ssh_user',
        'port',
        'transport',
        'dns',
        'enable_ipv6',
        'enable_logging',
        'enable_proxy',
        'header1',
        'header2',
        'deployment_status',
        'deployment_log',
        'status',
        'status_log_path',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

    public function vpnUsers(): BelongsToMany
    {
        return $this->belongsToMany(VpnUser::class, 'vpn_user_server', 'server_id', 'user_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class, 'vpn_server_id');
    }

    public function activeConnections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class, 'vpn_server_id')->where('is_connected', true);
    }
    

    // ─── Deployment log helper ──────────────────────────────────────
    public function appendLog(string $line): void
    {
        Log::info("APPEND_LOG: " . $line);
        $existing = trim($this->deployment_log ?? '');
        $lines = $existing === '' ? [] : explode("\n", $existing);

        if (!in_array($line, $lines)) {
            $lines[] = $line;
            $this->update([
                'deployment_log' => implode("\n", $lines),
            ]);
        }
    }

    // ─── Accessors & Mutators ───────────────────────────────────────

    public function getOnlineUserCount(): int
    {
        // Validate IP address before attempting remote command
        if (empty($this->ip_address)) {
            Log::warning("⚠️ Cannot get online user count for server $this->name: IP address is null or empty");
            return 0;
        }

        $statusPath = $this->status_log_path ?? '/run/openvpn/server.status'; // Updated to match deployment script configuration

        try {
            // Count client lines between "Common Name" and "ROUTING TABLE"
            $result = $this->executeRemoteCommand(
            $this,
            "awk '/Common Name/{flag=1;next}/ROUTING TABLE/{flag=0}flag' $statusPath | wc -l"
        );
        
            if ($result['status'] === 0 && isset($result['output'][0])) {
                return (int) trim($result['output'][0]);
            }
        } catch (Exception $e) {
            Log::error("❌ Failed to get online user count for server $this->name: " . $e->getMessage());
        }

        return 0;
    }

    public function getSshCommand(): string
    {
        $ip = $this->ip_address;

        // Validate IP address
        if (empty($ip)) {
            Log::error("❌ Cannot generate SSH command for server $this->name: IP address is null or empty");
            throw new InvalidArgumentException("Server IP address is required to generate SSH command");
        }

        $port = $this->ssh_port ?? 22;
        $user = $this->ssh_user ?? 'root';

        // Create a temporary directory for SSH operations to avoid permission issues
        $tempSshDir = storage_path('app/temp_ssh');
        if (!is_dir($tempSshDir)) {
            mkdir($tempSshDir, 0700, true);
        }

        if ($this->ssh_type === 'key') {
            // Handle both filename and full path scenarios for ssh_key
            if (str_starts_with($this->ssh_key, '/') || str_contains($this->ssh_key, ':\\')) {
                // ssh_key contains full path (Unix or Windows style)
                $keyPath = $this->ssh_key;
            } else {
                // ssh_key contains just filename, construct full path
                $keyPath = storage_path('app/ssh_keys/' . $this->ssh_key);
            }
            return "ssh -i $keyPath -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o UserKnownHostsFile=$tempSshDir/known_hosts -p $port $user@$ip";
        }

        return "sshpass -p '$this->ssh_password' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o UserKnownHostsFile=$tempSshDir/known_hosts -p $port $user@$ip";
    }




    // ─── Status Helpers ─────────────────────────────────────────────

    public function getIsOnlineAttribute(): bool
    {
        // Treat servers that finished deployment as "online"
        // (swap this logic later to a health-check flag if you want)
        return $this->deployment_status === 'succeeded';
    }
    public function killClient(string $username): bool
{
    $res = $this->killClientDetailed($username);

    Log::info(sprintf(
        '🔌 killClient %s@%s -> ok=%s status=%s out=%s',
        $username,
        $this->name,
        $res['ok'] ? '1' : '0',
        $res['status'],
        implode(' | ', $res['output'] ?? [])
    ));

    return $res['ok'];
}

/**
 * @return array{ok:bool,status:int,output:array<string>}
 */
public function killClientDetailed(string $username): array
{
    $mgmtHost = '127.0.0.1';
    $mgmtPort = 7505;

    // Safe for inclusion in a double-quoted bash string
    $needle = addcslashes($username, "\\\"`$");

    // NOTE: No single quotes inside this script.
    $script = "
OUT=\$({ printf \"status 3\\r\\n\"; printf \"quit\\r\\n\"; } | nc -w 3 {$mgmtHost} {$mgmtPort} 2>/dev/null)
NEEDLE=\"{$needle}\"

# Find by CN (col 2) OR Username (col 10). Output: CN<TAB>CID
PAIR=\$(printf \"%s\\n\" \"\$OUT\" | awk -F \"\\t\" -v q=\"\$NEEDLE\" '
  \$1==\"CLIENT_LIST\" {
    cn=\$2; gsub(/\\r\$/, \"\", cn);
    user=\$10; gsub(/\\r\$/, \"\", user);
    if (cn==q || user==q) { print cn \"\\t\" \$11; exit }
  }')

CN=\$(printf \"%s\" \"\$PAIR\" | cut -f1)
CID=\$(printf \"%s\" \"\$PAIR\" | cut -f2)

if [ -z \"\$CID\" ]; then
  echo \"ERR: no CID for user/CN: \$NEEDLE\"
  # Helpful dump for debugging (first few CLIENT_LIST lines)
  printf \"%s\\n\" \"\$OUT\" | awk -F\"\\t\" '\$1==\"CLIENT_LIST\"{print \$2 \"|\" \$10 \"|\" \$11}' | head -n 5
  exit 2
fi

# Try CN+CID first (preferred)
RES=\$(printf \"client-kill %s %s\\r\\nquit\\r\\n\" \"\$CN\" \"\$CID\" | nc -w 3 {$mgmtHost} {$mgmtPort} 2>/dev/null)
echo \"REPLY1: \$RES\"
case \"\$RES\" in *SUCCESS*|*success*) exit 0 ;; esac

# Fallback: some builds accept just CID
RES=\$(printf \"client-kill %s\\r\\nquit\\r\\n\" \"\$CID\" | nc -w 3 {$mgmtHost} {$mgmtPort} 2>/dev/null)
echo \"REPLY2: \$RES\"
case \"\$RES\" in *SUCCESS*|*success*) exit 0 ;; esac

# Older fallback
RES=\$(printf \"kill %s\\r\\nquit\\r\\n\" \"\$CID\" | nc -w 3 {$mgmtHost} {$mgmtPort} 2>/dev/null)
echo \"REPLY3: \$RES\"
case \"\$RES\" in *SUCCESS*|*success*) exit 0 ;; esac

exit 3
";

    // Run on the box via your trait (note: your trait expects the model instance first)
    $res = $this->executeRemoteCommand($this, "bash -lc " . escapeshellarg($script));

    return [
        'ok'     => (int)($res['status'] ?? 1) === 0,
        'status' => (int)($res['status'] ?? 1),
        'output' => $res['output'] ?? [],
    ];
}

    // ─── Status Scopes ──────────────────────────────────────────────

    // ─── SSH Command Generator ──────────────────────────────────────

    // ─── Boot Hooks ─────────────────────────────────────────────────
    protected static function booted(): void
    {
        static::creating(function (self $vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = 'id_rsa';  // Changed this line
            }
        });

        static::updating(function (self $vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = 'id_rsa';  // Changed this line
            }
        });
    }
}
