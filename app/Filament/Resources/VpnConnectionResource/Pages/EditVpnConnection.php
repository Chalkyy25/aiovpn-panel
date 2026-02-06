<?php

namespace App\Filament\Resources\VpnConnectionResource\Pages;

use App\Filament\Resources\VpnConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnConnection extends EditRecord
{
    protected static string $resource = VpnConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
