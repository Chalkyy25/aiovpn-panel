<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use App\Models\VpnUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    /** @var array<int> */
    protected array $vpnServerIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Force reseller ownership
        $data['client_id'] = auth()->id();

        // Pull server IDs from the submitted form payload
        $ids = Arr::get($data, 'vpn_server_ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->vpnServerIds = array_values(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0
        ));

        // Remove virtual fields so they don't hit mass assignment
        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        // Defaults
        $data['is_active'] = $data['is_active'] ?? true;
        $data['max_connections'] = $data['max_connections'] ?? 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        // Sync pivot once the record exists
        $record->syncVpnServers($this->vpnServerIds, context: 'reseller.create');
    }

    protected function getRedirectUrl(): string
    {
        // Go back to VPN Users list (index) after creating
        return static::$resource::getUrl('index');
    }
}