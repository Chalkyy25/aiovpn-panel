<?php

namespace App\Filament\Resources\WireguardPeerResource\Pages;

use App\Filament\Resources\WireguardPeerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWireguardPeers extends ListRecords
{
    protected static string $resource = WireguardPeerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
