<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Pre-fill virtual selection with currently assigned server IDs
        $data['vpn_server_ids'] = $this->record->vpnServers()->pluck('vpn_servers.id')->map(fn ($id) => (int) $id)->all();
        return $data;
    }

    protected function afterSave(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        $this->record->vpnServers()->sync($ids);
    }
}