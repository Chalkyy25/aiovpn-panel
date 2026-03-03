<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
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

        // Reseller owns the line
        $data['client_id'] ??= auth()->id();

        // Track creator (same as admin logic)
        $data['created_by'] ??= auth()->id();

        // Defaults
        $data['is_active'] ??= true;
        $data['max_connections'] ??= $data['max_connections'] ?? 1;

        // Remove virtual fields (NOT DB columns)
        unset($data['all_servers'], $data['package_id'], $data['vpn_server_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        // Always read from $this->data
        $ids = (array) ($this->data['vpn_server_ids'] ?? []);
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        // 1) Attach servers + any protocol-specific jobs you already trigger in syncVpnServers
        $record->syncVpnServers($ids, context: 'reseller.create');

        // 2) Provision WireGuard peers for all selected servers that support WG
        $wgFailures = 0;

        try {
            if (! empty($ids)) {
                $servers = VpnServer::query()->whereIn('id', $ids)->get();

                /** @var WireGuardService $wg */
                $wg = app(WireGuardService::class);

                foreach ($servers as $server) {
                    if (! $server->supportsWireGuard()) {
                        continue;
                    }

                    try {
                        $wg->ensurePeerForUser($server, $record);
                    } catch (\Throwable $e) {
                        $wgFailures++;

                        Log::error('❌ Reseller: WG provisioning failed for server', [
                            'vpn_user_id' => $record->id,
                            'server_id'   => $server->id,
                            'server_ip'   => $server->ip_address,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // This should be rare; keep it from killing the UX.
            Log::error('❌ Reseller: WG provisioning failed (outer)', [
                'vpn_user_id' => $record->id,
                'server_ids'  => $ids,
                'error'       => $e->getMessage(),
            ]);

            $wgFailures = max($wgFailures, 1);
        }

        if ($wgFailures > 0) {
            Notification::make()
                ->warning()
                ->title('VPN user created, but some WireGuard peers failed')
                ->body("Failed on {$wgFailures} server(s). Check logs.")
                ->send();
        }

        Notification::make()
            ->success()
            ->title('VPN user created')
            ->body("Username: {$record->username}\nPassword: " . ($record->plain_password ?? '******'))
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}