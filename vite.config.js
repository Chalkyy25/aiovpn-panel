import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        host: '0.0.0.0',

        hmr: process.env.CODESPACE_NAME
            ? {
                  protocol: 'wss',
                  host: `${process.env.CODESPACE_NAME}-5173.app.github.dev`,
              }
            : undefined,
    },

    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});