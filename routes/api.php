<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VpnSessionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/device/register', function (Request $request) {
    $request->validate([
        'username' => 'required|string',
        'device_name' => 'required|string',
    ]);

    $vpnUser = \App\Models\VpnUser::where('username', $request->username)->firstOrFail();
    $vpnUser->device_name = $request->device_name;
    $vpnUser->save();

    return response()->json(['status' => 'success']);
});

/*
|--------------------------------------------------------------------------
| VPN Session Management API Routes (Admin Only)
|--------------------------------------------------------------------------
*/

Route::prefix('vpn/sessions')->group(function () {
    // Kick a VPN user from their active sessions
    Route::post('{userId}/kick', [VpnSessionController::class, 'kickUser'])->name('api.vpn.sessions.kick');
    
    // Get active sessions for a user
    Route::get('{userId}/active', [VpnSessionController::class, 'getActiveSessions'])->name('api.vpn.sessions.active');
    
    // Get kick history for a user
    Route::get('{userId}/kick-history', [VpnSessionController::class, 'getKickHistory'])->name('api.vpn.sessions.kick-history');
    
    // Health check endpoint
    Route::get('health', [VpnSessionController::class, 'healthCheck'])->name('api.vpn.sessions.health');
});
