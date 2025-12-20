<?php

namespace App\Http\Controllers;

use App\Models\AppBuild;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppBuildDownloadController extends Controller
{
    public function latest(): StreamedResponse
    {
        $build = AppBuild::query()
            ->where('is_active', true)
            ->latest('id')
            ->firstOrFail();

        $path = $build->apk_path;

        abort_if(!$path, 404, 'No APK path set for the active build.');

        // Works whether you store on "public" disk or default disk
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->download($path, 'AIO-VPN.apk');
        }

        abort_if(!Storage::exists($path), 404, 'APK file not found on disk.');

        return Storage::download($path, 'AIO-VPN.apk');
    }
}