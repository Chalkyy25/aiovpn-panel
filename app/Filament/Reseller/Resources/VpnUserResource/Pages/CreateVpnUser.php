<?php

namespace App\Filament\Reseller\Resources\VpnUserResource\Pages;

use App\Filament\Reseller\Resources\VpnUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnUser extends CreateRecord
{
    protected static string $resource = VpnUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // lock ownership
        $data['client_id'] = auth()->id();

        // defaults if missing
        $data['is_active'] = $data['is_active'] ?? true;
        $data['max_connections'] = $data['max_connections'] ?? 1;

        return $data;
    }
}