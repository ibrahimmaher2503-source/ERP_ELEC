import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/passkeys.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: { host: '127.0.0.1' },
});
