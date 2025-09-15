<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\Api\DeployEventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Endpoints used by your remote deployment script and the panel.
| Protected with the custom 'auth.panel-token' middleware (Bearer token).
|
| NOTE: route-model binding for {server} will inject App\Models\VpnServer
| because your controller action type-hints VpnServer $server.
*/

Route::middleware('auth.panel-token')->group(function () {
    // ── Provisioning pings ─────────────────────────────────────────
    Route::post('/servers/{server}/provision/start',  [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update', [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish', [ProvisioningController::class, 'finish']);

    // ── Deployment/events + logs streaming ─────────────────────────
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs',   [DeployApiController::class, 'log']);

    // ── Realtime management status (preferred unified endpoint) ────
    // This feeds your ServerMgmtEvent broadcast used by the dashboard.
    Route::post('/servers/{server}/events', [DeployEventController::class, 'store'])
        ->name('api.servers.events.store');

    // (optional) If your script already posts here, keep them:
    Route::post('/servers/{server}/mgmt/push',     [DeployApiController::class, 'pushMgmt']);
    Route::post('/servers/{server}/mgmt/snapshot', [DeployApiController::class, 'pushMgmtSnapshot']);

    // ── Facts reported after install ───────────────────────────────
    Route::post('/servers/{server}/deploy/facts',  [DeployApiController::class, 'facts']);

    // ── Auth file (script pulls + mirror back) ─────────────────────
    Route::get ('/servers/{server}/authfile',      [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile',      [DeployApiController::class, 'uploadAuthFile']);
});

// ── Mobile client endpoints ───────────────────────────────────────────
Route::post('/auth/login', [MobileAuthController::class, 'login']);   // return token
Route::middleware('auth:sanctum')->get('/ping', function (Request $req) {
    return response()->json([
        'ok'   => true,
        'user' => $req->user()->only('id','email')
    ]);
});

/*
| If (and only if) you need a Sanctum-secured alias for testing from the panel,
| you can uncomment this. Your deploy script should prefer the Bearer token route above.
|
| Route::middleware('auth:sanctum')
|     ->post('/servers/{server}/events', [DeployEventController::class, 'store'])
|     ->name('api.servers.events.store.sanctum');
*/

// Simple device registration endpoint (unchanged)
Route::post('/device/register', function (Request $request) {
    $request->validate([
        'username'    => 'required|string',
        'device_name' => 'required|string',
    ]);

    $vpnUser = \App\Models\VpnUser::where('username', $request->username)->firstOrFail();
    $vpnUser->device_name = $request->device_name;
    $vpnUser->save();

    return response()->json(['status' => 'success']);
});