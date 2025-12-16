<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Class Namespace
    |--------------------------------------------------------------------------
    */
    'class_namespace' => 'App\\Livewire',

    /*
    |--------------------------------------------------------------------------
    | View Path
    |--------------------------------------------------------------------------
    */
    'view_path' => resource_path('views/livewire'),

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */
    'layout' => 'layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Lazy Loading Placeholder
    |--------------------------------------------------------------------------
    */
    'lazy_placeholder' => null,

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | - max is in KILOBYTES
    | - 204800 KB = 200 MB
    | - This OVERRIDES Livewire's default 12MB cap
    |
    */
    'temporary_file_upload' => [

        // Use local disk (recommended for APKs)
        'disk' => 'local',

        // Explicit rules — DO NOT leave null
        'rules' => [
            'required',
            'file',
            'max:204800', // 200MB
        ],

        // Temp upload directory
        'directory' => 'livewire-tmp',

        // Keep default throttle unless abuse becomes an issue
        'middleware' => null,

        // Preview types (not used for APKs but harmless)
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg',
            'wav', 'mp4', 'mov', 'avi', 'wmv',
            'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],

        // APK uploads can take time on mobile
        'max_upload_time' => 15, // minutes

        // Clean up old temp uploads automatically
        'cleanup' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Render On Redirect
    |--------------------------------------------------------------------------
    */
    'render_on_redirect' => false,

    /*
    |--------------------------------------------------------------------------
    | Legacy Model Binding
    |--------------------------------------------------------------------------
    */
    'legacy_model_binding' => false,

    /*
    |--------------------------------------------------------------------------
    | Auto-inject Frontend Assets
    |--------------------------------------------------------------------------
    */
    'inject_assets' => true,

    /*
    |--------------------------------------------------------------------------
    | Navigate (SPA mode)
    |--------------------------------------------------------------------------
    */
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML Morph Markers
    |--------------------------------------------------------------------------
    */
    'inject_morph_markers' => true,

    /*
    |--------------------------------------------------------------------------
    | Pagination Theme
    |--------------------------------------------------------------------------
    */
    'pagination_theme' => 'tailwind',

];