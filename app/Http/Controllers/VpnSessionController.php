<?php

namespace App\Http\Controllers;

use App\Services\VpnSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VpnSessionController extends Controller
{
    private VpnSessionService $vpnSessionService;

    public function __construct(VpnSessionService $vpnSessionService)
    {
        $this->vpnSessionService = $vpnSessionService;
        
        // Apply admin middleware to all methods
        $this->middleware(['auth:sanctum', 'role:admin']);
    }

    /**
     * Kick a VPN user from their active sessions
     *
     * @param Request $request
     * @param int $userId VPN user ID
     * @return JsonResponse
     */
    public function kickUser(Request $request, int $userId): JsonResponse
    {
        try {
            // Validate request data
            $validatedData = $request->validate([
                'reason' => 'nullable|string|max:255',
            ]);

            $reason = $validatedData['reason'] ?? null;
            $admin = Auth::user();

            // Log the kick attempt
            Log::info("Admin {$admin->email} attempting to kick VPN user ID: {$userId}", [
                'admin_id' => $admin->id,
                'vpn_user_id' => $userId,
                'reason' => $reason,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // Perform the kick
            $result = $this->vpnSessionService->kickUser($userId, $admin, $reason);

            // Return appropriate response based on result
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'kicked_sessions' => $result['kicked_sessions'] ?? 0,
                        'total_sessions' => $result['total_sessions'] ?? 0,
                        'errors' => $result['errors'] ?? []
                    ]
                ], 200);
            } else {
                $statusCode = $this->getStatusCodeFromError($result['error_code'] ?? 'UNKNOWN');
                
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'UNKNOWN',
                    'errors' => $result['errors'] ?? []
                ], $statusCode);
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            Log::error("VpnSessionController::kickUser error: " . $e->getMessage(), [
                'vpn_user_id' => $userId,
                'admin_id' => Auth::id(),
                'exception' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while kicking the user.',
                'error_code' => 'INTERNAL_SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Get active sessions for a VPN user
     *
     * @param int $userId
     * @return JsonResponse
     */
    public function getActiveSessions(int $userId): JsonResponse
    {
        try {
            $result = $this->vpnSessionService->getActiveSessions($userId);

            return response()->json([
                'success' => true,
                'data' => $result['sessions'],
                'count' => $result['count']
            ], 200);

        } catch (\Exception $e) {
            Log::error("VpnSessionController::getActiveSessions error: " . $e->getMessage(), [
                'vpn_user_id' => $userId,
                'admin_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active sessions.',
                'error_code' => 'INTERNAL_SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Get kick history for a VPN user
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function getKickHistory(Request $request, int $userId): JsonResponse
    {
        try {
            $limit = $request->query('limit', 50);
            $limit = min(max((int)$limit, 1), 100); // Ensure limit is between 1 and 100

            $result = $this->vpnSessionService->getKickHistory($userId, $limit);

            return response()->json([
                'success' => true,
                'data' => $result['history'],
                'count' => $result['count'],
                'limit' => $limit
            ], 200);

        } catch (\Exception $e) {
            Log::error("VpnSessionController::getKickHistory error: " . $e->getMessage(), [
                'vpn_user_id' => $userId,
                'admin_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve kick history.',
                'error_code' => 'INTERNAL_SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Get appropriate HTTP status code from error code
     *
     * @param string $errorCode
     * @return int
     */
    private function getStatusCodeFromError(string $errorCode): int
    {
        return match ($errorCode) {
            'INSUFFICIENT_PERMISSIONS' => 403,
            'USER_NOT_FOUND' => 404,
            'NO_ACTIVE_SESSIONS' => 404,
            'INTERNAL_ERROR' => 500,
            default => 400
        };
    }

    /**
     * Health check endpoint for VPN session service
     *
     * @return JsonResponse
     */
    public function healthCheck(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'VPN Session Service is operational',
                'timestamp' => now()->toISOString(),
                'service' => 'VpnSessionService',
                'version' => '1.0.0'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'VPN Session Service health check failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}