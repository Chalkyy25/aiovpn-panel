<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnUser extends EditRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
