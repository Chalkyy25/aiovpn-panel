<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    /**
     * vpn_server_id is NOT a real DB column on vpn_users.
     * Strip it out before create so Eloquent doesn't try to save it.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['vpn_server_id']);
        return $data;
    }

    /**
     * After user is created, sync pivot.
     * This will make them assigned to EXACTLY ONE server.
     */
    protected function afterCreate(): void
    {
        $serverId = (int) ($this->data['vpn_server_id'] ?? 0);

        if ($serverId > 0) {
            $this->record->vpnServers()->sync([$serverId]); // exactly one
        }
    }
}