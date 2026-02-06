<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['vpn_server_ids'], $data['select_all_servers']);
        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];

        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        $this->record->vpnServers()->sync($ids); // ✅ multi server
    }
}