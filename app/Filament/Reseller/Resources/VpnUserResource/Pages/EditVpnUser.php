<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use App\Models\VpnUser;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    /** @var array<int> */
    protected array $vpnServerIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var VpnUser $record */
        $record = $this->record;

        // Prefill selected servers for the multi-select
        $data['vpn_server_ids'] = $record->vpnServers()
            ->pluck('vpn_servers.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // IMPORTANT:
        // vpn_server_ids is a virtual field (dehydrated(false)) so Filament will NOT include it in $data.
        // Read it from $this->data (the full form state) to avoid wiping server assignments on every edit.
        $rawIds = $this->data['vpn_server_ids'] ?? Arr::get($data, 'vpn_server_ids', []);

        $this->vpnServerIds = array_values(array_filter(
            array_map('intval', (array) $rawIds),
            fn (int $id) => $id > 0
        ));

        // Strip virtual fields
        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        // Reseller must NEVER be able to change ownership
        unset($data['client_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        $record->syncVpnServers($this->vpnServerIds, context: 'reseller.edit');
    }
}