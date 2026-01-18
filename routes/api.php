<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\Api\DeployEventController;
use App\Http\Controllers\Api\WireGuardEventController;

use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileProfileController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\GenericStealthConfigController;
use App\Http\Controllers\WireGuardConfigController;

use App\Http\Controllers\Api\GateV2Controller;
use App\Http\Controllers\GateLoginController;

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\AppUpdateController;
use App\Models\VpnUser;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ✅ SERVER → PANEL (must be authenticated with panel-token)
Route::prefix('servers/{server}')
    ->middleware('auth.panel-token')
    ->group(function () {

        // Provisioning lifecycle
        Route::post('/provision/start',  [ProvisioningController::class, 'start']);
        Route::post('/provision/update', [ProvisioningController::class, 'update']);
        Route::post('/provision/finish', [ProvisioningController::class, 'finish']);

        // Deploy events + logs
        Route::post('/deploy/events', [DeployApiController::class, 'event']);
        Route::post('/deploy/logs',   [DeployApiController::class, 'log']);
        Route::post('/deploy/facts',  [DeployApiController::class, 'facts']);

        // Unified ingest
        Route::post('/events', [DeployEventController::class, 'store'])
            ->name('api.servers.events.store');

        // WireGuard ingest
        Route::post('/wireguard-events', [WireGuardEventController::class, 'store'])
            ->name('api.servers.wireguard-events.store');

        // Legacy mgmt feeds
        Route::post('/mgmt/push',     [DeployApiController::class, 'pushMgmt']);
        Route::post('/mgmt/snapshot', [DeployApiController::class, 'pushMgmtSnapshot']);

        // Auth file (pull + upload)
        Route::get('/authfile',  [DeployApiController::class, 'authFile']);
        Route::post('/authfile', [DeployApiController::class, 'uploadAuthFile']);
    });

Route::post('/gate/v2/auth', [GateV2Controller::class, 'auth']);
Route::post('/gate/login', [GateLoginController::class, 'login']);

/* =======================
| MOBILE CLIENT
======================= */

Route::post('/auth/login', [MobileAuthController::class, 'login']);


Route::prefix('stealth')->group(function () {
    Route::get('/servers',           [GenericStealthConfigController::class, 'servers']);
    Route::get('/config/{serverId}', [GenericStealthConfigController::class, 'config']);
    Route::get('/info/{serverId}',   [GenericStealthConfigController::class, 'configInfo']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profiles',        [MobileProfileController::class, 'index']);
    Route::get('/profiles/{user}', [MobileProfileController::class, 'show']);
    Route::get('/ovpn',            [MobileProfileController::class, 'ovpn']);

    Route::get('/wg/servers', [WireGuardConfigController::class, 'servers']);
    Route::get('/wg/config',  [WireGuardConfigController::class, 'config']);

    Route::get('/locations', [LocationController::class, 'index']);

    Route::get('/ping', function (Request $req) {
        $u = $req->user();
        return response()->json([
            'ok'   => true,
            'user' => method_exists($u, 'only') ? $u->only('id', 'username') : null,
        ]);
    });
});

/* =======================
| PUBLIC / MISC
======================= */

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

/* =======================
| APP UPDATER (device token)
======================= */

Route::post('/devices/register-token', [DeviceController::class, 'register']);

Route::middleware('device.token')->group(function () {
    Route::get('/app/latest',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download/{id}', [AppUpdateController::class, 'download']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/app/latest-sanctum',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download-sanctum/{id}', [AppUpdateController::class, 'download']);
});
