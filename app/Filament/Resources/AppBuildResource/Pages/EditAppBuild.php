<?php

namespace App\Filament\Resources\AppBuildResource\Pages;

use App\Filament\Resources\AppBuildResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAppBuild extends EditRecord
{
    protected static string $resource = AppBuildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
