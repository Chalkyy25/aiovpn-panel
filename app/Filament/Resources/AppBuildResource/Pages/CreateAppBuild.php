<?php

namespace App\Filament\Resources\AppBuildResource\Pages;

use App\Filament\Resources\AppBuildResource;
use App\Models\AppBuild;
use Filament\Resources\Pages\CreateRecord;

class CreateAppBuild extends CreateRecord
{
    protected static string $resource = AppBuildResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['is_active'])) {
            AppBuild::query()->update(['is_active' => false]);
        }

        if (! empty($data['apk_path'])) {
            $fullPath = storage_path('app/' . $data['apk_path']);

            if (file_exists($fullPath)) {
                $data['sha256'] = hash_file('sha256', $fullPath);
            }
        }

        return $data;
    }
}
