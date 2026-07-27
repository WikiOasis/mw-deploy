import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * No remote webfonts: the portal runs on the Salt master, which is not
 * necessarily allowed to reach the public internet, and an ops tool that waits
 * on a CDN to render is an ops tool that fails when you need it. The system font
 * stack in resources/css/app.css covers it.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
