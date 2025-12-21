<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppBuild;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppUpdateController extends Controller
{
    public function latest(Request $request)
    {
        $build = AppBuild::where('is_active', true)->orderByDesc('version_code')->first();
        if (!$build) {
            return response()->json(['message' => 'No build available'], 404);
        }

        $base = rtrim(config('app.public_api_base'), '/');

        return response()->json([
            'id'            => $build->id,
            'version_code'  => (int) $build->version_code,
            'version_name'  => $build->version_name,
            'mandatory'     => (bool) $build->mandatory,
            'release_notes' => $build->release_notes,
            'sha256'        => $build->sha256,
            'apk_url'       => "{$base}/api/app/download/{$build->id}",
        ]);
    }

    public function download(Request $request, int $id)
{
    $build = AppBuild::findOrFail($id);

    $filename = "aiovpn-{$build->version_name}.apk";

    return Storage::disk('local')->download(
        $build->apk_path,
        $filename,
        [
            'Content-Type'  => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]
    );
}
}
