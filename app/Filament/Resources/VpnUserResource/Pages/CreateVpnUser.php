<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use App\Models\Package;
use App\Models\VpnServer;
use App\Services\WireGuardService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // package_id + vpn_server_ids are virtual (dehydrated(false)),
        // so they won't be inside $data. Use $this->data instead.

        $packageId = (int) ($this->data['package_id'] ?? 0);

        if ($packageId > 0 && ($package = Package::query()->find($packageId))) {
            $data['max_connections'] = (int) $package->max_connections;

            $months = (int) $package->duration_months;
            $data['expires_at'] = $months <= 0 ? null : now()->addMonthsNoOverflow($months);
        }

        // Ensure ownership is set so the default Owner filter (client_id = me)
        // shows newly created lines immediately.
        $data['client_id'] ??= auth()->id();
        $data['created_by'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        // 1) Sync pivot + trigger any protocol-specific jobs you already have
        $this->record->syncVpnServers($ids, context: 'admin.create');

        // 2) If any selected server supports WireGuard, provision WG peer now
        try {
            if (! empty($ids)) {
                $servers = VpnServer::query()->whereIn('id', $ids)->get();

                /** @var WireGuardService $wg */
                $wg = app(WireGuardService::class);

                foreach ($servers as $server) {
                    if ($server->supportsWireGuard()) {
                        $wg->ensurePeerForUser($server, $this->record);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('❌ Filament: WG provisioning failed on create vpn user', [
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

        // 3) Success notification
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