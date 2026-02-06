<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use App\Models\Package;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Package controls expiry + max_connections (authoritative server-side).
        $packageId = (int) ($data['package_id'] ?? 0);
        if ($packageId > 0 && ($package = Package::query()->find($packageId))) {
            $data['max_connections'] = (int) $package->max_connections;

            $months = (int) $package->duration_months;
            $data['expires_at'] = $months <= 0 ? null : now()->addMonthsNoOverflow($months);
        }

        unset($data['package_id']); // virtual field
        unset($data['vpn_server_ids']); // virtual field
        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        $this->record->syncVpnServers($ids, context: 'filament.create');

        // Match old flow: show created credentials
        Notification::make()
            ->success()
            ->title('VPN user created')
            ->body("Username: {$this->record->username}\nPassword: " . ($this->record->plain_password ?? '******'))
            ->send();
    }
}