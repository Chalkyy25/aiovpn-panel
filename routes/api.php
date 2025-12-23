<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Models\VpnUser;

// Panel / deploy
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\Api\DeployEventController;
use App\Http\Controllers\Api\WireGuardEventController;

// Mobile client
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileProfileController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\GenericStealthConfigController;
use App\Http\Controllers\WireGuardConfigController;

// App updater (device token)
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\AppUpdateController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| AUTH TYPES (IMPORTANT):
| - auth.panel-token  => server deployment/panel automation
| - auth:sanctum      => mobile client login + user access (VpnUser tokens)
| - device.token      => app updater system (separate device token model)
|--------------------------------------------------------------------------
*/

/* =======================================================================
| PANEL / DEPLOY (server-to-panel automation)
| Protected by: auth.panel-token
======================================================================= */
Route::middleware('auth.panel-token')->group(function () {

    // Provisioning lifecycle
    Route::post('/servers/{server}/provision/start',  [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update', [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish', [ProvisioningController::class, 'finish']);

    // Deployment events + logs
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs',   [DeployApiController::class, 'log']);

    // Unified realtime event ingestion (preferred)
    Route::post('/servers/{server}/events', [DeployEventController::class, 'store'])
        ->name('api.servers.events.store');

    // WireGuard-specific event ingestion
    Route::post('/servers/{server}/wireguard-events', [WireGuardEventController::class, 'store'])
        ->name('api.servers.wireguard-events.store');

    // Legacy mgmt feeds (optional)
    Route::post('/servers/{server}/mgmt/push',     [DeployApiController::class, 'pushMgmt']);
    Route::post('/servers/{server}/mgmt/snapshot', [DeployApiController::class, 'pushMgmtSnapshot']);

    // Facts posted after install completes
    Route::post('/servers/{server}/deploy/facts', [DeployApiController::class, 'facts']);

    // Auth file (pull + upload)
    Route::get('/servers/{server}/authfile',  [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile', [DeployApiController::class, 'uploadAuthFile']);
});


/* =======================================================================
| MOBILE CLIENT (VPN user app)
| Login is public, everything else requires Sanctum
======================================================================= */

// Login (returns Sanctum token + user info)
Route::post('/auth/login', [MobileAuthController::class, 'login']);

// Public generic stealth configs (for AIO Smarters app)
Route::prefix('stealth')->group(function () {
    Route::get('/servers',           [GenericStealthConfigController::class, 'servers']);
    Route::get('/config/{serverId}', [GenericStealthConfigController::class, 'config']);
    Route::get('/info/{serverId}',   [GenericStealthConfigController::class, 'configInfo']);
});

// Authenticated mobile routes (Sanctum)
Route::middleware('auth:sanctum')->group(function () {

    // Profile summary + assigned servers
    Route::get('/profiles', [MobileProfileController::class, 'index']);

    // Per-user profile
    Route::get('/profiles/{user}', [MobileProfileController::class, 'show']);

    // OVPN text endpoint
    Route::get('/ovpn', [MobileProfileController::class, 'ovpn']);

    // WireGuard
    Route::get('/wg/servers', [WireGuardConfigController::class, 'servers']);
    Route::get('/wg/config',  [WireGuardConfigController::class, 'config']);

    // Locations (authenticated variant)
    Route::get('/locations', [LocationController::class, 'index']);

    // Simple ping
    Route::get('/ping', function (Request $req) {
        $u = $req->user();
        return response()->json([
            'ok'   => true,
            'user' => method_exists($u, 'only') ? $u->only('id', 'username') : null,
        ]);
    });
});


/* =======================================================================
| PUBLIC / MISC
======================================================================= */

// Old device registration (kept as-is)
Route::post('/device/register', function (Request $request) {
    $request->validate([
        'username'    => 'required|string',
        'device_name' => 'required|string',
    ]);

    $vpnUser = VpnUser::where('username', $request->username)->firstOrFail();
    $vpnUser->device_name = $request->device_name;
    $vpnUser->save();

    return response()->json(['status' => 'success']);
});


/* =======================================================================
| APP UPDATER (device-token system)
| Protected by: device.token
| NOTE: These endpoints will NOT accept Sanctum tokens.
======================================================================= */

// Device-token registration (do NOT replace /device/register)
Route::post('/devices/register-token', [DeviceController::class, 'register']);

// Updater endpoints (device.token only)
Route::middleware('device.token')->group(function () {
    Route::get('/app/latest',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download/{id}', [AppUpdateController::class, 'download']);
});


/* =======================================================================
| OPTIONAL: Sanctum aliases for updater endpoints (for curl/testing)
| This avoids the "Invalid token" confusion when testing /app/latest
======================================================================= */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/app/latest-sanctum',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download-sanctum/{id}', [AppUpdateController::class, 'download']);
});
