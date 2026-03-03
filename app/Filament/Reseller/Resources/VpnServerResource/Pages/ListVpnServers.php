<?php

namespace App\Filament\Reseller\Resources\VpnServerResource\Pages;

use App\Filament\Reseller\Resources\VpnServerResource;
use Filament\Resources\Pages\ListRecords;

class ListVpnServers extends ListRecords
{
    protected static string $resource = VpnServerResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only for resellers.
        return [];
    }
}
