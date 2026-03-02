<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use App\Models\VpnUser;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['client_id'] = auth()->id();

        // optional defaults
        $data['is_active'] ??= true;
        $data['max_connections'] ??= 1;

        // remove virtual fields (NOT DB columns)
        unset($data['all_servers'], $data['package_id'], $data['vpn_server_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        // ✅ always read from $this->data (same as admin)
        $ids = (array) ($this->data['vpn_server_ids'] ?? []);
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        $record->syncVpnServers($ids, context: 'reseller.create');
    }

    protected function getRedirectUrl(): string
    {
        // ✅ go back to list
        return static::getResource()::getUrl('index');
    }
}