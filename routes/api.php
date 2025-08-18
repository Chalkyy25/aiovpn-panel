<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProvisioningController;

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
    // script → panel: status updates (optional but nice)
    Route::post('/servers/{server}/provision/start',   [ProvisioningController::class, 'start']);
    Route::post('/servers/{server}/provision/update',  [ProvisioningController::class, 'update']);
    Route::post('/servers/{server}/provision/finish',  [ProvisioningController::class, 'finish']);

    // script → panel: get current OpenVPN password file for this server
    Route::get('/servers/{server}/openvpn/psw-file',   [ProvisioningController::class, 'passwordFile']);
});

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
