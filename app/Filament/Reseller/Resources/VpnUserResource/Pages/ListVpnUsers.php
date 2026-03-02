<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVpnUsers extends ListRecords
{
    protected static string $resource = VpnUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
