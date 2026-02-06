<?php

namespace App\Filament\Resources\VpnConnectionResource\Pages;

use App\Filament\Resources\VpnConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVpnConnections extends ListRecords
{
    protected static string $resource = VpnConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
