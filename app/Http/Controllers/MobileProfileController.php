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
            $sshUser = config('services.vpn_nodes.ssh_user', 'root');
            $sshKeyPath = config('services.vpn_nodes.ssh_key', '/root/.ssh/id_rsa');

            $key = @file_get_contents($sshKeyPath);
            if ($key === false) return ['ok' => false, 'reason' => "Missing SSH key: $sshKeyPath"];

            $priv = PublicKeyLoader::loadPrivateKey($key);
            $ssh  = new SSH2($host, 22, 8); // 8s timeout
            if (!$ssh->login($sshUser, $priv)) {
                return ['ok' => false, 'reason' => 'SSH login failed'];
            }

            // find conf path
            $confCandidates = [
                '/etc/openvpn/server/server.conf',
                '/etc/openvpn/server.conf',
                '/etc/openvpn/openvpn.conf',
            ];
            $conf = '';
            foreach ($confCandidates as $p) {
                $out = $ssh->exec("test -r $p && cat $p || true");
                if ($out && trim($out) !== '') { $conf = $out; break; }
            }
            if ($conf === '') return ['ok' => false, 'reason' => 'server.conf not found'];

            // detect proto/port
            $proto = 'udp'; $port = 1194;
            if (preg_match('/^\s*proto\s+(\S+)/mi', $conf, $m)) $proto = trim($m[1]);
            if (preg_match('/^\s*port\s+(\d+)/mi', $conf, $m))  $port  = (int) $m[1];

            // detect tls mode and key path
            $mode = 'tls-auth';
            $keyPath = null;
            if (preg_match('/^\s*tls-crypt\s+(\S+)/mi', $conf, $m)) {
                $mode = 'tls-crypt';
                $keyPath = $m[1];
            } elseif (preg_match('/^\s*tls-auth\s+(\S+)/mi', $conf, $m)) {
                $mode = 'tls-auth';
                $keyPath = $m[1];
            } else {
                // common fallbacks
                $keyPath = '/etc/openvpn/server/ta.key';
            }

            // read CA and key (resolve relative key path if necessary)
            $caPaths = [
                '/etc/openvpn/server/ca.crt',
                '/etc/openvpn/ca.crt',
                '/etc/openvpn/easy-rsa/pki/ca.crt',
                '/etc/openvpn/pki/ca.crt',
            ];
            $ca = '';
            foreach ($caPaths as $p) {
                $out = $ssh->exec("test -r $p && cat $p || true");
                if ($out && trim($out) !== '') { $ca = $out; break; }
            }

            // If key path is relative, try under /etc/openvpn/server/
            $kp = $keyPath;
            if ($kp && str_starts_with($kp, './')) $kp = substr($kp, 2);
            if ($kp && !str_starts_with($kp, '/')) $kp = "/etc/openvpn/server/$kp";

            $key = '';
            if ($kp) {
                $key = $ssh->exec("test -r $kp && cat $kp || true");
            }

            if (trim($ca) === '' || trim($key) === '') {
                return ['ok' => false, 'reason' => 'Empty CA or key'];
            }

            return [
                'ok'              => true,
                'proto'           => $proto,
                'port'            => $port,
                'mode'            => $mode,                 // tls-auth or tls-crypt
                'ca'              => $ca,
                'ta_or_tlscrypt'  => $key,
            ];
        } catch (\Throwable $e) {
            Log::error('SSH fetch failed: '.$e->getMessage(), ['host' => $host]);
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }
}