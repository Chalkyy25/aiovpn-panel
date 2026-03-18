<?php

namespace App\Filament\Resources\AppBuildResource\Pages;

use App\Filament\Resources\AppBuildResource;
use App\Models\AppBuild;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditAppBuild extends EditRecord
{
    protected static string $resource = AppBuildResource::class;

    protected ?string $oldApkPath = null;

    protected function beforeFill(): void
    {
        $this->oldApkPath = $this->record->apk_path;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['is_active'])) {
            AppBuild::where('id', '!=', $this->record->id)
                ->update(['is_active' => false]);
        }

        if (! empty($data['apk_path'])) {
            $fullPath = storage_path('app/' . $data['apk_path']);

            if (file_exists($fullPath)) {
                $data['sha256'] = hash_file('sha256', $fullPath);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $newApkPath = $this->record->apk_path;

        if (
            $this->oldApkPath &&
            $newApkPath &&
            $this->oldApkPath !== $newApkPath &&
            Storage::disk('local')->exists($this->oldApkPath)
        ) {
            Storage::disk('local')->delete($this->oldApkPath);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (): void {
                    if ($this->record->apk_path && Storage::disk('local')->exists($this->record->apk_path)) {
                        Storage::disk('local')->delete($this->record->apk_path);
                    }
                }),
        ];
    }
}
