<?php

namespace App\Services;

use App\Models\User;
use App\Models\VpnUser;
use App\Models\VpnSession;
use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Support\Facades\Log;
use Exception;

class VpnSessionService
{
    use ExecutesRemoteCommands;

    /**
     * Kick a VPN user from their active sessions
     *
     * @param int $userId VPN user ID
     * @param User $kickedBy Admin user performing the kick
     * @param string|null $reason Optional reason for kicking
     * @return array Result array with success status and messages
     */
    public function kickUser(int $userId, User $kickedBy, ?string $reason = null): array
    {
        try {
            // Validate admin permissions
            if (!$kickedBy->isAdmin()) {
                return [
                    'success' => false,
                    'message' => 'Insufficient permissions. Admin access required.',
                    'error_code' => 'INSUFFICIENT_PERMISSIONS'
                ];
            }

            // Find the VPN user
            $vpnUser = VpnUser::find($userId);
            if (!$vpnUser) {
                return [
                    'success' => false,
                    'message' => 'VPN user not found.',
                    'error_code' => 'USER_NOT_FOUND'
                ];
            }

            // Get active sessions
            $activeSessions = VpnSession::where('user_id', $userId)
                ->where('is_active', true)
                ->get();

            if ($activeSessions->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No active sessions found for this user.',
                    'error_code' => 'NO_ACTIVE_SESSIONS'
                ];
            }

            $results = [];
            $successCount = 0;
            $errors = [];

            // Process each active session
            foreach ($activeSessions as $session) {
                try {
                    // Kick the session via OpenVPN management interface
                    $kickResult = $this->kickSessionFromOpenVPN($vpnUser, $session);
                    
                    if ($kickResult['success']) {
                        // Mark session as kicked
                        $session->kick($kickedBy, $reason);
                        $successCount++;
                        
                        $results[] = [
                            'session_id' => $session->session_id,
                            'success' => true,
                            'message' => 'Session kicked successfully'
                        ];
                    } else {
                        $errors[] = [
                            'session_id' => $session->session_id,
                            'error' => $kickResult['message']
                        ];
                    }
                } catch (Exception $e) {
                    Log::error("Error kicking session {$session->session_id}: " . $e->getMessage());
                    $errors[] = [
                        'session_id' => $session->session_id,
                        'error' => 'Failed to kick session: ' . $e->getMessage()
                    ];
                }
            }

            // Update VPN user status
            if ($successCount > 0) {
                $vpnUser->update([
                    'is_online' => false,
                    'last_seen_at' => now(),
                ]);
            }

            // Log the action
            Log::info("Admin {$kickedBy->email} kicked VPN user {$vpnUser->username}. Successful kicks: {$successCount}, Errors: " . count($errors));

            // Prepare response
            $message = $successCount > 0 
                ? "Successfully kicked {$successCount} session(s)." 
                : "Failed to kick any sessions.";

            if (!empty($errors)) {
                $message .= " " . count($errors) . " error(s) occurred.";
            }

            return [
                'success' => $successCount > 0,
                'message' => $message,
                'kicked_sessions' => $successCount,
                'total_sessions' => $activeSessions->count(),
                'errors' => $errors,
                'results' => $results
            ];

        } catch (Exception $e) {
            Log::error("VpnSessionService::kickUser error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while kicking the user.',
                'error_code' => 'INTERNAL_ERROR',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Kick a session via OpenVPN management interface
     *
     * @param VpnUser $vpnUser
     * @param VpnSession $session
     * @return array
     */
    private function kickSessionFromOpenVPN(VpnUser $vpnUser, VpnSession $session): array
    {
        try {
            // Get associated VPN servers for this user
            $servers = $vpnUser->vpnServers;
            
            if ($servers->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No VPN servers associated with this user.'
                ];
            }

            $kickedFromServer = false;
            $lastError = '';

            // Try to kick from each server
            foreach ($servers as $server) {
                try {
                    $result = $this->killClientOnServer($server, $vpnUser->username);
                    
                    if ($result['success']) {
                        $kickedFromServer = true;
                        Log::info("Successfully kicked {$vpnUser->username} from server {$server->name}");
                    } else {
                        $lastError = $result['message'] ?? 'Unknown error';
                        Log::warning("Failed to kick {$vpnUser->username} from server {$server->name}: {$lastError}");
                    }
                } catch (Exception $e) {
                    $lastError = $e->getMessage();
                    Log::error("Error kicking {$vpnUser->username} from server {$server->name}: {$lastError}");
                }
            }

            return [
                'success' => $kickedFromServer,
                'message' => $kickedFromServer 
                    ? 'User kicked from OpenVPN server(s)' 
                    : "Failed to kick user from servers: {$lastError}"
            ];

        } catch (Exception $e) {
            Log::error("Error in kickSessionFromOpenVPN: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error communicating with OpenVPN server: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Kill a client connection on a specific OpenVPN server
     *
     * @param VpnServer $server
     * @param string $username
     * @return array
     */
    private function killClientOnServer(VpnServer $server, string $username): array
    {
        try {
            // Use the management interface to kill the client
            // First, try to connect to the management interface
            $managementCommand = "echo 'kill {$username}' | nc localhost 7505";
            
            $result = $this->executeRemoteCommand(
                $server->ip_address,
                $managementCommand
            );

            if ($result['status'] === 0) {
                return [
                    'success' => true,
                    'message' => 'Client killed successfully'
                ];
            } else {
                // Fallback: try alternative methods
                return $this->killClientAlternativeMethod($server, $username);
            }

        } catch (Exception $e) {
            Log::error("Error killing client {$username} on server {$server->name}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to kill client: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Alternative method to kill client if management interface fails
     *
     * @param VpnServer $server
     * @param string $username
     * @return array
     */
    private function killClientAlternativeMethod(VpnServer $server, string $username): array
    {
        try {
            // Try to find and kill the process
            $findProcessCommand = "ps aux | grep openvpn | grep {$username} | awk '{print \$2}' | head -1";
            
            $result = $this->executeRemoteCommand(
                $server->ip_address,
                $findProcessCommand
            );

            if ($result['status'] === 0 && !empty($result['output'])) {
                $pid = trim(implode('', $result['output']));
                
                if (is_numeric($pid)) {
                    $killResult = $this->executeRemoteCommand(
                        $server->ip_address,
                        "kill -TERM {$pid}"
                    );

                    return [
                        'success' => $killResult['status'] === 0,
                        'message' => $killResult['status'] === 0 
                            ? 'Client process killed successfully' 
                            : 'Failed to kill client process'
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Could not find client process to kill'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Alternative kill method failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get active sessions for a user
     *
     * @param int $userId
     * @return array
     */
    public function getActiveSessions(int $userId): array
    {
        $sessions = VpnSession::where('user_id', $userId)
            ->where('is_active', true)
            ->with(['vpnUser', 'kickedByUser'])
            ->get();

        return [
            'success' => true,
            'sessions' => $sessions->toArray(),
            'count' => $sessions->count()
        ];
    }

    /**
     * Get kick history for a user
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getKickHistory(int $userId, int $limit = 50): array
    {
        $history = KickHistory::forUser($userId)
            ->with(['vpnUser', 'kickedByUser'])
            ->orderBy('kicked_at', 'desc')
            ->limit($limit)
            ->get();

        return [
            'success' => true,
            'history' => $history->toArray(),
            'count' => $history->count()
        ];
    }
}