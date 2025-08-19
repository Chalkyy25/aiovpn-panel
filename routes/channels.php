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

Broadcast::channel('servers.{server}', function ($user, VpnServer $server) {
    // Allow if the user is logged-in and either an admin or linked to this server
    return $user
        && (
            ($user->is_admin ?? false)
            || $user->vpnServers()->whereKey($server->id)->exists()
        );
});

Broadcast::channel('servers.dashboard', function ($user) {
    // Any authenticated user can listen to dashboard stream
    return (bool) $user;
});