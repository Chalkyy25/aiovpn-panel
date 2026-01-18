<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class GateV2Controller extends Controller
{
    public function auth(Request $request): JsonResponse
    {
        // Validate minimal input
        $data = $request->validate([
            'xtream_base' => ['required', 'string'],
            'username'    => ['required', 'string'],
            'password'    => ['required', 'string'],
            'device'      => ['nullable', 'array'],
        ]);

        // DO NOT log credentials
        // DO NOT store credentials

        // TEMP: always allow for now
        return response()->json([
            'ok' => true,

            'stremio' => [
                'package' => 'com.stremio.one',
            ],

            'addons' => [
                [
                    'name'     => 'AIO Catalog',
                    'manifest' => 'stremio://addon-install?manifest=' . urlencode('https://addon.aiodev.work/manifest.json'),
                ],
                [
                    'name'     => 'Torrentio',
                    'manifest' => 'stremio://torrentio.strem.fun/manifest.json',
                ],
            ],

            'post_install' => [
                'launch_stremio' => true,
            ],
        ]);
    }
}
