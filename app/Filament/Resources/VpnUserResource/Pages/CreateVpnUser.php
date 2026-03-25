<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\WireGuardIpAllocator;
use App\Services\WireGuardService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $packageId = (int) ($this->data['package_id'] ?? 0);

        if ($packageId > 0 && ($package = Package::query()->find($packageId))) {
            $data['max_connections'] = (int) $package->max_connections;

            $months = (int) $package->duration_months;
            $data['expires_at'] = $months <= 0 ? null : now()->addMonthsNoOverflow($months);
        }

        $data['client_id'] ??= auth()->id();
        $data['created_by'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        // 1) Ensure WG identity exists BEFORE any WG provisioning
        try {
            $dirty = false;

            if (blank($this->record->wireguard_private_key) || blank($this->record->wireguard_public_key)) {
                $keys = VpnUser::generateWireGuardKeys();
                $this->record->wireguard_private_key = $keys['private'];
                $this->record->wireguard_public_key  = $keys['public'];
                $dirty = true;
            }

            if (blank($this->record->wireguard_address)) {
                $this->record->wireguard_address = WireGuardIpAllocator::next();
                $dirty = true;
            }

            if ($dirty) {
                $this->record->save();
            }

            Log::channel('vpn')->info('FILAMENT_CREATE_VPN_USER: WG identity ensured', [
                'vpn_user_id' => $this->record->id,
                'username' => $this->record->username,
                'wireguard_address' => $this->record->wireguard_address,
            ]);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error('FILAMENT_CREATE_VPN_USER: failed ensuring WG identity', [
                'vpn_user_id' => $this->record->id,
                'username' => $this->record->username,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('VPN user created, but WireGuard identity failed')
                ->body($e->getMessage())
                ->send();

            return;
        }

        // 2) Sync pivot + protocol jobs/logging
        $this->record->syncVpnServers($ids, context: 'admin.create');

        // 3) Provision WG peers on selected WG-capable servers
        try {
            if (! empty($ids)) {
                $servers = VpnServer::query()->whereIn('id', $ids)->get();

                /** @var WireGuardService $wg */
                $wg = app(WireGuardService::class);

                foreach ($servers as $server) {
                    if ($server->supportsWireGuard()) {
                        $wg->ensurePeerForUser($server, $this->record);

                        Log::channel('vpn')->info('FILAMENT_CREATE_VPN_USER: WG peer ensured', [
                            'vpn_user_id' => $this->record->id,
                            'username' => $this->record->username,
                            'server_id' => $server->id,
                            'server_name' => $server->name,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('vpn')->error('FILAMENT_CREATE_VPN_USER: WG provisioning failed', [
                'vpn_user_id' => $this->record->id,
                'server_ids'  => $ids,
                'error'       => $e->getMessage(),
            ]);

            Notification::make()
                ->danger()
                ->title('VPN user created, but WireGuard provisioning failed')
                ->body($e->getMessage())
                ->send();
        }

        // 4) Success
        Notification::make()
            ->success()
            ->title('VPN user created')
            ->body("Username: {$this->record->username}\nPassword: " . ($this->record->plain_password ?? '******'))
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}