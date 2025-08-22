<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you register all event broadcasting channels your app supports.
| The given callbacks determine if the authenticated user can listen.
|
*/

// VPN Server private channels
Broadcast::channel('servers.{serverId}', function ($user, int $serverId) {
    // 🔒 for now allow all, later check if $user can access $serverId
    return true;
});

// Global dashboard channel (if you want to push global stats)
Broadcast::channel('servers.dashboard', function ($user) {
    return true;
});
