<?php

namespace App\Filament\Resources\VpnUserResource\Pages;

use App\Filament\Resources\VpnUser resource;
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