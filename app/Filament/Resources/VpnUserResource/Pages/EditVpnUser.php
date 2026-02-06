<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUserResource;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    /**
     * Prefill the virtual field from pivot.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['vpn_server_id'] = $this->record
            ->vpnServers()
            ->value('vpn_servers.id');

        return $data;
    }

    /**
     * vpn_server_id is virtual. Remove before Eloquent update.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['vpn_server_id']);
        return $data;
    }

    /**
     * After save, sync pivot.
     * Keeps exactly one server assignment.
     */
    protected function afterSave(): void
    {
        $serverId = (int) ($this->data['vpn_server_id'] ?? 0);

        if ($serverId > 0) {
            $this->record->vpnServers()->sync([$serverId]); // exactly one
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}