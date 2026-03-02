import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css', // ✅ ADD THIS
                'resources/css/filament/reseller/theme.css', // optional if using reseller theme
            ],
            refresh: true,
        }),
    ],
});