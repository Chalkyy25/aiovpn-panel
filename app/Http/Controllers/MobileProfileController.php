<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\VpnUser;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class MobileProfileController extends Controller
{
    public function index(Request $request)
    {
        /** @var VpnUser $user */
        $user = $request->user();

        $servers = $user->vpnServers()->get()->map(function ($s) {
            return [
                'id'      => $s->id,
                'name'    => $s->name ?? ('Server '.$s->id),
                'ip'      => $s->ip_address ?? $s->ip ?? null,
                'proto'   => $s->proto ?? null, // may be null -> will be auto-detected
                'port'    => $s->port ?? null,  // may be null -> will be auto-detected
            ];
        });

        return response()->json([
            'id'        => $user->id,
            'username'  => $user->username,
            'expires'   => $user->expires_at,
            'max_conn'  => (int) $user->max_connections,
            'servers'   => $servers,
        ]);
    }

    public function show(Request $request, VpnUser $user)
    {
        if ($request->user()->id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $server = $user->vpnServers()->first();
        if (!$server) return response("No server assigned to this user", 404);

        $host = $server->ip_address ?? $server->ip;
        if (!$host) return response("Server has no IP set", 500);

        // pull configs via SSH (cached to avoid repeated reads)
        $cacheKey = "ovpn_assets:server:{$server->id}";
        $assets = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return $this->fetchOpenVPNAssetsOverSSH($host);
        });

        if (!$assets['ok']) {
            Log::warning('OpenVPN assets fetch failed', ['host' => $host, 'reason' => $assets['reason'] ?? null]);
            return response("Could not read OpenVPN files from $host", 502);
        }

        $proto = $server->proto ?? $assets['proto'] ?? 'udp';
        $port  = $server->port  ?? $assets['port']  ?? 1194;
        $ca    = trim($assets['ca'] ?? '');
        $key   = trim($assets['ta_or_tlscrypt'] ?? '');
        $mode  = $assets['mode'] ?? 'tls-auth'; // 'tls-auth' or 'tls-crypt'

        $config = view('vpn.ovpn-template', [
            'host'  => $host,
            'port'  => $port,
            'proto' => $proto,
            'ca'    => $ca,
            'key'   => $key,
            'mode'  => $mode,
        ])->render();

        return response($config, 200, [
            'Content-Type'        => 'application/x-openvpn-profile',
            'Content-Disposition' => "attachment; filename=aio-{$user->username}.ovpn",
        ]);
    }

    /**
     * SSH into the node and read server.conf, ca.crt, and key (ta.key or tls-crypt.key).
     */
    private function fetchOpenVPNAssetsOverSSH(string $host): array
{
    try {
        $sshUser   = config('services.vpn_nodes.ssh_user', 'root');
        $sshKey    = config('services.vpn_nodes.ssh_key',  '/root/.ssh/id_rsa');
        $sshPort   = (int) config('services.vpn_nodes.ssh_port', 22);

        $keyStr = @file_get_contents($sshKey);
        if ($keyStr === false) return ['ok' => false, 'reason' => "Missing SSH key: $sshKey"];

        $priv = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($keyStr);
        $ssh  = new \phpseclib3\Net\SSH2($host, $sshPort, 8);
        if (!$ssh->login($sshUser, $priv)) return ['ok' => false, 'reason' => 'SSH login failed'];

        // Prefer your path; keep a couple fallbacks
        $confPaths = [
            '/etc/openvpn/server.conf',          // your setup
            '/etc/openvpn/server/server.conf',
            '/etc/openvpn/openvpn.conf',
        ];
        $conf = ''; $confPath = '';
        foreach ($confPaths as $p) {
            $out = $ssh->exec("test -r $p && cat $p || true");
            if ($out && trim($out) !== '') { $conf = $out; $confPath = $p; break; }
        }
        if ($conf === '') return ['ok' => false, 'reason' => 'server.conf not found'];

        $baseDir = rtrim(dirname($confPath), '/'); // e.g. /etc/openvpn

        // Parse fields
        $proto = 'udp'; $port = 1194; $mode = 'tls-auth';
        $caPath = null; $keyPath = null;
        if (preg_match('/^\s*proto\s+(\S+)/mi', $conf, $m)) $proto = trim($m[1]);
        if (preg_match('/^\s*port\s+(\d+)/mi',  $conf, $m)) $port  = (int) $m[1];
        if (preg_match('/^\s*ca\s+(\S+)/mi',    $conf, $m)) $caPath = trim($m[1]);

        if (preg_match('/^\s*tls-crypt\s+(\S+)/mi', $conf, $m)) {
            $mode = 'tls-crypt'; $keyPath = trim($m[1]);
        } elseif (preg_match('/^\s*tls-auth\s+(\S+)/mi', $conf, $m)) {
            $mode = 'tls-auth';  $keyPath = trim($m[1]);
        }

        // Resolve relative paths against $baseDir
        $resolve = function (?string $p) use ($baseDir) {
            if (!$p) return null;
            if ($p[0] === '/') return $p;                // absolute
            if (str_starts_with($p, './')) $p = substr($p, 2);
            return "$baseDir/$p";                        // relative
        };
        $caResolved  = $resolve($caPath)  ?? "$baseDir/ca.crt";
        $keyResolved = $resolve($keyPath) ?? "$baseDir/ta.key";

        // Read files
        $ca  = $ssh->exec("test -r '$caResolved'  && cat '$caResolved'  || true");
        $key = $ssh->exec("test -r '$keyResolved' && cat '$keyResolved' || true");

        if (trim($ca) === '' || trim($key) === '') {
            return ['ok' => false, 'reason' => "Empty CA or key ($caResolved | $keyResolved)"];
        }

        return [
            'ok'              => true,
            'proto'           => $proto,
            'port'            => $port,
            'mode'            => $mode,               // tls-auth or tls-crypt
            'ca'              => $ca,
            'ta_or_tlscrypt'  => $key,
        ];
    } catch (\Throwable $e) {
        \Log::error('SSH fetch failed: '.$e->getMessage(), ['host' => $host]);
        return ['ok' => false, 'reason' => $e->getMessage()];
    }
}
}