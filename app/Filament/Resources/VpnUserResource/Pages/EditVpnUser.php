<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use App\Models\VpnServer;
use App\Services\WireGuardService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Pre-fill virtual selection with currently assigned server IDs
        $data['vpn_server_ids'] = $this->record->vpnServers()->pluck('vpn_servers.id')->map(fn ($id) => (int) $id)->all();
        return $data;
    }

    protected function afterSave(): void
    {
        $ids = $this->data['vpn_server_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        // 1) Sync pivot + dispatch OpenVPN creds for attached + WG reconcile for detached
        $changes = $this->record->syncVpnServers($ids, context: 'admin.edit');

        $attachedIds = array_values(array_map('intval', $changes['attached'] ?? []));

        // 2) Provision WG peers for newly attached WG-capable servers.
        //    WireGuardService::ensurePeerForUser() calls ensureIdentity() internally,
        //    so it generates the keypair + allocates an IP if the user has none.
        $wgFailures = 0;

        if (! empty($attachedIds)) {
            $servers = VpnServer::query()->whereIn('id', $attachedIds)->get();

            /** @var WireGuardService $wg */
            $wg = app(WireGuardService::class);

            foreach ($servers as $server) {
                if (! $server->supportsWireGuard()) {
                    continue;
                }

                try {
                    $wg->ensurePeerForUser($server, $this->record);

                    Log::channel('vpn')->info('FILAMENT_EDIT_VPN_USER: WG peer ensured', [
                        'vpn_user_id' => $this->record->id,
                        'username'    => $this->record->username,
                        'server_id'   => $server->id,
                        'server_name' => $server->name,
                    ]);
                } catch (\Throwable $e) {
                    $wgFailures++;

                    Log::channel('vpn')->error('FILAMENT_EDIT_VPN_USER: WG peer provisioning failed', [
                        'vpn_user_id' => $this->record->id,
                        'username'    => $this->record->username,
                        'server_id'   => $server->id,
                        'server_name' => $server->name,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }

        // 3) Notifications
        if ($wgFailures > 0) {
            Notification::make()
                ->warning()
                ->title('VPN user saved, but WireGuard provisioning failed on some servers')
                ->body("Failed on {$wgFailures} server(s). Check logs.")
                ->send();
        } else {
            Notification::make()
                ->success()
                ->title('VPN user updated')
                ->body("Changes saved for {$this->record->username}.")
                ->send();
        }
    }
}
