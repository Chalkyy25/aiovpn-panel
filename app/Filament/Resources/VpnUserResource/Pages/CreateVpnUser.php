<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function afterCreate(): void
    {
        $serverId = (int) ($this->data['vpn_server_id'] ?? 0);

        if ($serverId > 0) {
            $this->record->vpnServers()->sync([$serverId]);
        }
    }
}