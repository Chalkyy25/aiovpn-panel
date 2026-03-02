<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected array $vpnServerIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // lock ownership
        $data['client_id'] = auth()->id();

        // store and remove virtual fields
        $this->vpnServerIds = $data['vpn_server_ids'] ?? [];
        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        // defaults
        $data['is_active'] ??= true;
        $data['max_connections'] ??= 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var \App\Models\VpnUser $record */
        $record = $this->record;

        $ids = array_values(array_filter(array_map('intval', $this->vpnServerIds), fn ($id) => $id > 0));
        $record->syncVpnServers($ids, context: 'reseller.create');
    }
}