<?php

use App\Http\Controllers\Api\GenericStealthConfigController;
use App\Http\Controllers\WireGuardConfigController;
use App\Models\VpnUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\Api\DeployEventController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\AppUpdateController;




use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileProfileController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| - Panel/Deploy endpoints: protected by custom 'auth.panel-token'
| - Mobile client endpoints: protected by Sanctum (except /auth/login)
*/

/* ========================== PANEL / DEPLOY ========================== */

Route::middleware('auth.panel-token')->group(function () {
    // Provisioning lifecycle
    Route::post('/servers/{server}/provision/start',  [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update', [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish', [ProvisioningController::class, 'finish']);

    // Deployment events + streamed logs
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs',   [DeployApiController::class, 'log']);

    // Unified realtime management status (preferred)
    Route::post('/servers/{server}/events', [DeployEventController::class, 'store'])
        ->name('api.servers.events.store');

    // (Optional legacy) separate mgmt feeds
    Route::post('/servers/{server}/mgmt/push',     [DeployApiController::class, 'pushMgmt']);
    Route::post('/servers/{server}/mgmt/snapshot', [DeployApiController::class, 'pushMgmtSnapshot']);

    // Facts posted after install completes
    Route::post('/servers/{server}/deploy/facts',  [DeployApiController::class, 'facts']);

    // Server auth file (pull + mirror back)
    Route::get ('/servers/{server}/authfile', [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile', [DeployApiController::class, 'uploadAuthFile']);
});

/* ========================== MOBILE CLIENT =========================== */

// Login (returns Sanctum token + user info)
Route::post('/auth/login', [MobileAuthController::class, 'login']);

// Public generic stealth configs (for AIO Smarters app)
Route::prefix('stealth')->group(function () {
    Route::get('/servers', [GenericStealthConfigController::class, 'servers']);
    Route::get('/config/{serverId}', [GenericStealthConfigController::class, 'config']);
    Route::get('/info/{serverId}', [GenericStealthConfigController::class, 'configInfo']);
});

// Authenticated mobile routes
Route::middleware('auth:sanctum')->group(function () {

    // Profile summary + assigned servers
    Route::get('/profiles', [MobileProfileController::class, 'index']);

    Route::get('/locations', [LocationController::class, 'index']);

    // Return a ready-to-import .ovpn for the given (or first) server
    Route::get('/profiles/{user}', [MobileProfileController::class, 'show']);

     // WireGuard for mobile
    Route::get('/wg/servers', [WireGuardConfigController::class, 'servers']);
    Route::get('/wg/config',  [WireGuardConfigController::class, 'config']);

    // *** Android app expects THIS endpoint: raw .ovpn text ***
    // Example: /api/ovpn?user_id=7&server_id=99
    Route::get('/ovpn', [MobileProfileController::class, 'ovpn']);

    // Simple ping for token checks
    Route::get('/ping', function (Request $req) {
        return response()->json([
            'ok'   => true,
            'user' => $req->user()->only('id', 'username'),
        ]);
    });
});

/* ========================== MISC / PUBLIC ========================== */

// Device registration (kept as-is)
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

/* ======================= APP UPDATER (DEVICE TOKEN) ======================= */

// New device-token registration for update system (do NOT replace /device/register)
Route::post('/devices/register-token', [DeviceController::class, 'register']);

// Protected updater endpoints
Route::middleware('device.token')->group(function () {
    Route::get('/app/latest', [AppUpdateController::class, 'latest']);
    Route::get('/app/download/{id}', [AppUpdateController::class, 'download']);
});


/* -------------------------------------------------------------------
| If you also want a Sanctum-only alias for events (testing from panel),
| you can re-enable this safely:
|
| Route::middleware('auth:sanctum')
|     ->post('/servers/{server}/events', [DeployEventController::class, 'store'])
|     ->name('api.servers.events.store.sanctum');
|--------------------------------------------------------------------*/
