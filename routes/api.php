<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;
use App\Http\Controllers\Api\DeployEventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Endpoints used by your remote deployment script and the panel.
| Protected with the custom 'auth.panel-token' middleware (Bearer token).
*/

Route::middleware('auth.panel-token')->group(function () {
    // Optional: provisioning pings (if you use them)
    Route::post('/servers/{server}/provision/start',  [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update', [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish', [ProvisioningController::class, 'finish']);

    // Deployment/event + logs streaming from the script
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs',   [DeployApiController::class, 'log']);

    // Management status push (script -> panel; JSON with status/clients)
    Route::post('/servers/{server}/mgmt/push',     [DeployApiController::class, 'pushMgmt']);
    Route::post('/servers/{server}/mgmt/snapshot', [DeployApiController::class, 'pushMgmtSnapshot']);

    // Facts the script reports after install (iface, ports, proto, etc.)
    Route::post('/servers/{server}/deploy/facts',  [DeployApiController::class, 'facts']);

    // Password file for OpenVPN (script pulls + can mirror back)
    Route::get ('/servers/{server}/authfile',      [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile',      [DeployApiController::class, 'uploadAuthFile']);
    
});


Route::middleware('auth:sanctum')
    ->post('servers/{server}/deploy/events', [DeployEventController::class, 'store']);


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