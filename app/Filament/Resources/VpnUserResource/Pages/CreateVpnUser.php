<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function afterCreate(): void
    {
        $state = $this->form->getState();
        $serverId = $state['primary_server_id'] ?? null;

        if ($serverId) {
            // Uses your existing relationship pivot: vpn_server_user
            $this->record->vpnServers()->sync([$serverId]);
        }
    }
}
