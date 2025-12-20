<?php

namespace App\Http\Controllers;

use App\Models\AppBuild;
use Illuminate\Support\Facades\Storage;

class AppBuildPublicDownloadController
{
    public function latest()
    {
        $build = AppBuild::query()
            ->where('is_active', true)
            ->latest('id')
            ->firstOrFail();

        abort_if(!$build->apk_path, 404, 'No APK path set for latest build');

        $disk = Storage::disk('local');

        abort_if(!$disk->exists($build->apk_path), 404, 'APK not found');

        return $disk->download(
            $build->apk_path,
            'AIO-VPN.apk',
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'no-store',
            ]
        );
    }
}