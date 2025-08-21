<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\VpnServer;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('servers.{serverId}', function ($user, $serverId) {
    return true; // lock down later
});

Broadcast::channel('servers.dashboard', function ($user) {
    return true;
});