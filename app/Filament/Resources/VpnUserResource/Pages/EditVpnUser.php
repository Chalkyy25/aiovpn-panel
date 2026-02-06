<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function afterSave(): void
    {
        $serverId = (int) ($this->data['vpn_server_id'] ?? 0);

        if ($serverId > 0) {
            $this->record->vpnServers()->sync([$serverId]);
        } else {
            $this->record->vpnServers()->detach();
        }
    }
}