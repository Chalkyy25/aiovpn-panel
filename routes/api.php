<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\DeployApiController;

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

Route::middleware('auth.panel-token')->group(function () {
    // (Optional) provisioning status pings from script
    Route::post('/servers/{server}/provision/start',  [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update', [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish', [ProvisioningController::class, 'finish']);

    // script ↔ panel (what your deploy script calls)
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs',   [DeployApiController::class, 'log']);
    Route::get ('/servers/{server}/authfile',      [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile',      [DeployApiController::class, 'uploadAuthFile']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/servers/{server}/deploy/events', [DeployApiController::class, 'event']);
    Route::post('/servers/{server}/deploy/logs', [DeployApiController::class, 'log']);
    Route::get('/servers/{server}/authfile', [DeployApiController::class, 'authFile']);
    Route::post('/servers/{server}/authfile', [DeployApiController::class, 'uploadAuthFile']);
    // ...any other endpoints
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
