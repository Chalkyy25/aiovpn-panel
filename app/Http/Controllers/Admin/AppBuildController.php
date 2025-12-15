<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppBuild;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppBuildController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'version_code'   => 'required|integer|min:1',
            'version_name'   => 'required|string|max:50',
            'mandatory'      => 'nullable|boolean',
            'release_notes'  => 'nullable|string',
            'apk'            => 'required|file|mimetypes:application/vnd.android.package-archive,application/octet-stream|max:200000',
        ]);

        // Save APK privately (storage/app/app-updates)
        $path = $request->file('apk')->store('app-updates');

        // SHA256 checksum
        $fullPath = Storage::disk('local')->path($path);
        $sha256 = hash_file('sha256', $fullPath);

        // Optional: deactivate older builds
        AppBuild::where('is_active', true)->update(['is_active' => false]);

        $build = AppBuild::create([
            'version_code'  => $data['version_code'],
            'version_name'  => $data['version_name'],
            'apk_path'      => $path,
            'sha256'        => $sha256,
            'mandatory'     => (bool)($data['mandatory'] ?? false),
            'release_notes' => $data['release_notes'] ?? null,
            'is_active'     => true,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $build->id,
            'sha256' => $sha256,
            'apk_path' => $path,
        ]);
    }
}

