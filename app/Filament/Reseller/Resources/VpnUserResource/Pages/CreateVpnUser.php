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
        // Ownership is enforced here, always.
        $data['client_id'] = auth()->id();

        // Capture virtual/pivot fields (then remove them from mass-assignment)
        $this->vpnServerIds = array_values(array_filter(
            array_map('intval', Arr::get($data, 'vpn_server_ids', [])),
            fn (int $id) => $id > 0
        ));

        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        // Defaults (model boot() also covers some of these, but we keep it explicit)
        $data['is_active'] = $data['is_active'] ?? true;
        $data['max_connections'] = $data['max_connections'] ?? 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        // Sync pivot AFTER record exists.
        $record->syncVpnServers($this->vpnServerIds, context: 'reseller.create');
    }
}