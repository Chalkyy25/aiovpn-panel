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
        // IMPORTANT:
        // package_id + vpn_server_ids are virtual (dehydrated(false)),
        // so they won't be inside $data. Use $this->data instead.

        $packageId = (int) ($this->data['package_id'] ?? 0);

        if ($packageId > 0 && ($package = Package::query()->find($packageId))) {
            $data['max_connections'] = (int) $package->max_connections;

            $months = (int) $package->duration_months;
            $data['expires_at'] = $months <= 0 ? null : now()->addMonthsNoOverflow($months);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        // sync pivot
        $this->record->vpnServers()->sync($ids);

        // if you have jobs/logging when servers are attached, DO IT HERE
        // e.g. dispatch SyncOpenVPNCredentials per server if needed

        Notification::make()
            ->success()
            ->title('VPN user created')
            ->body("Username: {$this->record->username}\nPassword: " . ($this->record->plain_password ?? '******'))
            ->send();
    }
}