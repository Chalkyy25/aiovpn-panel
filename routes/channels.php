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

Broadcast::channel('servers.{serverId}', fn ($user = null, $serverId) => true);
Broadcast::channel('servers.dashboard', fn ($user = null) => true);