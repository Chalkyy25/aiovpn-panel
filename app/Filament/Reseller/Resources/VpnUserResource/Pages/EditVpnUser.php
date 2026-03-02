<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected array $vpnServerIds = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->vpnServerIds = $data['vpn_server_ids'] ?? [];

        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        // reseller should not be able to reassign ownership
        unset($data['client_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var \App\Models\VpnUser $record */
        $record = $this->record;

        $ids = array_values(array_filter(array_map('intval', $this->vpnServerIds), fn ($id) => $id > 0));
        $record->syncVpnServers($ids, context: 'reseller.edit');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // prefill selected servers
        $data['vpn_server_ids'] = $this->record->vpnServers()->pluck('vpn_servers.id')->map(fn ($id) => (int) $id)->all();

        return $data;
    }
}