<?php

namespace App\Filament\Resources\VpnServerResource\Pages;

use App\Filament\Resources\VpnServerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnServer extends EditRecord
{
    protected static string $resource = VpnServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
