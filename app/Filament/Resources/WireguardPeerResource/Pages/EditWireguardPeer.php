<?php

namespace App\Filament\Resources\WireguardPeerResource\Pages;

use App\Filament\Resources\WireguardPeerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWireguardPeer extends EditRecord
{
    protected static string $resource = WireguardPeerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
