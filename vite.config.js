import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        hmr: {
            protocol: 'wss',
            host: process.env.CODESPACE_NAME
                ? `${process.env.CODESPACE_NAME}-5173.app.github.dev`
                : 'localhost',
        },
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