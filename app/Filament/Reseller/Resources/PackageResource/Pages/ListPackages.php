<?php

namespace App\Filament\Reseller\Resources\PackageResource\Pages;

use App\Filament\Reseller\Resources\PackageResource;
use Filament\Resources\Pages\ListRecords;

class ListPackages extends ListRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only.
        return [];
    }
}
