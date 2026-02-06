<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function afterSave(): void
    {
        $state = $this->form->getState();
        $serverId = $state['primary_server_id'] ?? null;

        if ($serverId) {
            $this->record->vpnServers()->sync([$serverId]);
        }
    }
}
