<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppBuild;
use Illuminate\Support\Facades\Storage;

class AppBuildDownloadController extends Controller
{
    public function __invoke(AppBuild $build)
    {
        // Optional: add gate/role check here if needed

        $disk = Storage::disk('local');

        if (!$build->apk_path || !$disk->exists($build->apk_path)) {
            abort(404, 'APK not found');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $build->version_name ?: 'build');
        $filename = "AIOVPN-{$safeName}-{$build->version_code}.apk";

        return $disk->download($build->apk_path, $filename);
    }
}