<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use App\Models\VpnUser;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    /** @var array<int> */
    protected array $vpnServerIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Pre-fill virtual selection with currently assigned server IDs
        $data['vpn_server_ids'] = $this->record->vpnServers()->pluck('vpn_servers.id')->map(fn ($id) => (int) $id)->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // vpn_server_ids is virtual (dehydrated(false)); read from full form state.
        $rawIds = $this->data['vpn_server_ids'] ?? Arr::get($data, 'vpn_server_ids', []);

        $this->vpnServerIds = array_values(array_filter(
            array_map('intval', (array) $rawIds),
            fn (int $id) => $id > 0
        ));

        unset($data['vpn_server_ids'], $data['all_servers'], $data['package_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var VpnUser $record */
        $record = $this->record;

        $changes = $record->syncVpnServers($this->vpnServerIds, context: 'admin.edit');

        Notification::make()
            ->success()
            ->title('VPN user updated')
            ->send();

        $wgEnsureFailures = count((array) ($changes['wg_ensure_failed'] ?? []));
        $wgReconcileFailures = count((array) ($changes['wg_reconcile_failed'] ?? []));

        if ($wgEnsureFailures > 0 || $wgReconcileFailures > 0) {
            Notification::make()
                ->warning()
                ->title('VPN user updated with WireGuard warnings')
                ->body("WG ensure failures: {$wgEnsureFailures}, WG reconcile failures: {$wgReconcileFailures}. Check logs.")
                ->send();
        }
    }
}
