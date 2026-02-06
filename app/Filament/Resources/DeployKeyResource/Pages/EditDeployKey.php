<?php

namespace App\Filament\Resources\DeployKeyResource\Pages;

use App\Filament\Resources\DeployKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeployKey extends EditRecord
{
    protected static string $resource = DeployKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
