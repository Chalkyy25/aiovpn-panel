<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Models\VpnUser;

use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileProfileController;
use App\Http\Controllers\WireGuardConfigController;
use App\Http\Controllers\GateLoginController;

use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\Api\DeployEventController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\GateV2Controller;
use App\Http\Controllers\Api\GenericStealthConfigController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\WireGuardEventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| SERVER -> PANEL
|--------------------------------------------------------------------------
*/
Route::prefix('servers/{server}')
    ->middleware('auth.panel-token')
    ->group(function () {
        // Provisioning lifecycle
        Route::post('/provision/start',  [ProvisioningController::class, 'start']);
        Route::post('/provision/update', [ProvisioningController::class, 'update']);
        Route::post('/provision/finish', [ProvisioningController::class, 'finish']);

        // Deploy events + logs + facts
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

        // Auth file
        Route::get('/authfile',  [DeployApiController::class, 'authFile']);
        Route::post('/authfile', [DeployApiController::class, 'uploadAuthFile']);
    });

/*
|--------------------------------------------------------------------------
| GATE / LOGIN
|--------------------------------------------------------------------------
*/
Route::post('/gate/v2/auth', [GateV2Controller::class, 'auth']);
Route::post('/gate/login', [GateLoginController::class, 'login']);

/*
|--------------------------------------------------------------------------
| MOBILE AUTH
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| PUBLIC STEALTH
|--------------------------------------------------------------------------
*/
Route::prefix('stealth')->group(function () {
    Route::get('/servers',           [GenericStealthConfigController::class, 'servers']);
    Route::get('/config/{serverId}', [GenericStealthConfigController::class, 'config']);
    Route::get('/info/{serverId}',   [GenericStealthConfigController::class, 'configInfo']);
});

/*
|--------------------------------------------------------------------------
| PUBLIC / DEVICE REGISTRATION
|--------------------------------------------------------------------------
*/
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

Route::post('/devices/register-token', [DeviceController::class, 'register']);

/*
|--------------------------------------------------------------------------
| MOBILE CLIENT (SANCTUM)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/auth/logout', [MobileAuthController::class, 'logout']);
    Route::get('/auth/me', [MobileProfileController::class, 'index']);

    // Profiles
    Route::get('/profiles',        [MobileProfileController::class, 'index']);
    Route::get('/profiles/{user}', [MobileProfileController::class, 'show']);
    Route::get('/ovpn',            [MobileProfileController::class, 'ovpn']);

    // WireGuard
    Route::get('/wg/servers', [WireGuardConfigController::class, 'servers']);
    Route::get('/wg/config',  [WireGuardConfigController::class, 'config']);

    // Smart routing / app server list
    Route::get('/servers', [ServerController::class, 'index']);

    // Locations
    Route::get('/locations', [LocationController::class, 'index']);

    // Ping test
    Route::get('/ping', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'ok'   => true,
            'user' => method_exists($user, 'only')
                ? $user->only('id', 'username')
                : null,
        ]);
    });

    // App updater via Sanctum
    Route::get('/app/latest-sanctum',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download-sanctum/{id}', [AppUpdateController::class, 'download']);
});

/*
|--------------------------------------------------------------------------
| APP UPDATER (DEVICE TOKEN)
|--------------------------------------------------------------------------
*/
Route::middleware('device.token')->group(function () {
    Route::get('/app/latest',        [AppUpdateController::class, 'latest']);
    Route::get('/app/download/{id}', [AppUpdateController::class, 'download']);
});

/*
|--------------------------------------------------------------------------
| DEBUG
|--------------------------------------------------------------------------
*/
Route::get('/debug/routes', function () {
    $routes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->map(function ($route) {
            return [
                'method'     => implode('|', $route->methods()),
                'uri'        => $route->uri(),
                'name'       => $route->getName(),
                'action'     => $route->getActionName(),
                'middleware' => $route->middleware(),
            ];
        })
        ->values();

    return response()->json([
        'count'  => $routes->count(),
        'routes' => $routes,
    ]);
});
